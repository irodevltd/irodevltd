<?php

/**
 * Builds the profile cards in cards/, each in a light and a dark version:
 * - languages: the language mix of our public repositories, from GitHub's own language data;
 * - projects:  public projects, releases, test suites passing and Turkish documentation, counted live;
 * - stack:     the technologies we work with (the list on irodev.net/cozumler);
 * - services:  a short animated line of what we do.
 *
 * Runs every day in .github/workflows/cards.yml. Locally: php tools/cards.php (works without a token).
 * If GitHub can't be read, it stops with an error and leaves the existing cards untouched.
 */

declare(strict_types=1);

$owner = getenv('GITHUB_REPOSITORY_OWNER') ?: 'irodevltd';
$token = getenv('GITHUB_TOKEN') ?: '';
$out = dirname(__DIR__) . '/cards';
$skip = [strtolower($owner), '.github'];   // the profile repo and the shared community files hold no code

const SERVICES = ['Corporate websites', 'Online stores', 'Custom PHP software', 'Telegram bots', 'SEO and AI-search visibility'];
const STACK = [
    'php' => 'PHP', 'laravel' => 'Laravel', 'mysql' => 'MySQL', 'wordpress' => 'WordPress',
    'woocommerce' => 'WooCommerce', 'shopify' => 'Shopify', 'nextdotjs' => 'Next.js', 'html5' => 'HTML5',
    'css' => 'CSS', 'javascript' => 'JavaScript', 'python' => 'Python', 'telegram' => 'Telegram',
];
const BRAND = [
    'php' => '#777BB4', 'laravel' => '#FF2D20', 'mysql' => '#4479A1', 'wordpress' => '#21759B',
    'woocommerce' => '#96588A', 'shopify' => '#7AB55C', 'nextdotjs' => '#000000', 'html5' => '#E34F26',
    'css' => '#663399', 'javascript' => '#F7DF1E', 'python' => '#3776AB', 'telegram' => '#26A5E4',
];
// Label widths in Geist 500 at 14 px, measured in Chrome; other labels fall back to an estimate
const LABEL_W = [
    'PHP' => 28, 'Laravel' => 48, 'MySQL' => 48, 'WordPress' => 72, 'WooCommerce' => 101, 'Shopify' => 50,
    'Next.js' => 46, 'HTML5' => 47, 'CSS' => 29, 'JavaScript' => 72, 'Python' => 47, 'Telegram' => 61,
];
// GitHub's own language colours (github-linguist)
const LANG_COLORS = [
    'PHP' => '#4F5D95', 'JavaScript' => '#f1e05a', 'TypeScript' => '#3178c6', 'CSS' => '#663399', 'HTML' => '#e34c26',
    'Python' => '#3572A5', 'Shell' => '#89e051', 'Blade' => '#f7523f', 'Vue' => '#41b883', 'SCSS' => '#c6538c',
];
const THEMES = [
    'light' => ['bg' => '#ffffff', 'line' => '#e6e6df', 'ink' => '#141519', 'muted' => '#5f626a', 'chip' => '#f4f4ef', 'lime' => '#d8f23e', 'track' => '#efefe9'],
    'dark'  => ['bg' => '#141519', 'line' => '#2b2d34', 'ink' => '#f3f3ee', 'muted' => '#9b9ea6', 'chip' => '#1b1d23', 'lime' => '#d8f23e', 'track' => '#24262d'],
];

function api(string $path, string $token): ?array
{
    $headers = ['User-Agent: irodevltd-profile-cards', 'Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];
    if ($token !== '') { $headers[] = 'Authorization: Bearer ' . $token; }
    $body = @file_get_contents('https://api.github.com' . $path, false, stream_context_create(['http' => [
        'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 20,
    ]]));
    $head = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    $status = (int) (preg_match('~^HTTP/\S+ (\d{3})~', $head[0] ?? '', $m) ? $m[1] : 0);
    if ($status === 404) { return null; }
    if ($status !== 200 || $body === false) {
        throw new RuntimeException("GitHub answered $status for $path");
    }
    return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
}

function e(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

function luminance(string $hex): float
{
    $c = array_map(fn ($h) => hexdec($h) / 255, str_split(ltrim($hex, '#'), 2));
    $c = array_map(fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

function contrast(string $a, string $b): float
{
    [$x, $y] = [luminance($a), luminance($b)];
    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}

/** Moves a brand colour toward white (dark theme) or black (light theme) until it stands out from the chip. */
function legible(string $hex, string $bg, bool $dark): string
{
    $rgb = array_map('hexdec', str_split(ltrim($hex, '#'), 2));
    for ($i = 0; $i < 20 && contrast(vsprintf('#%02x%02x%02x', $rgb), $bg) < ($dark ? 2.6 : 1.6); $i++) {
        $rgb = array_map(fn ($v) => (int) round($dark ? $v + (255 - $v) * 0.15 : $v * 0.85), $rgb);
    }
    return vsprintf('#%02x%02x%02x', $rgb);
}

function svg(int $w, int $h, string $css, string $body, string $title): string
{
    static $font = null;
    $font ??= base64_encode((string) file_get_contents(__DIR__ . '/fonts/geist-latin.woff2'));
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($title) . '">'
        . '<title>' . e($title) . '</title><style>'
        . '@font-face{font-family:Geist;font-weight:100 900;src:url(data:font/woff2;base64,' . $font . ') format("woff2")}'
        . 'text{font-family:Geist,-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif}' . $css
        . '</style>' . $body . '</svg>' . "\n";
}

function card(array $t, int $w, int $h, string $title, string $sub, string $inner, string $foot): string
{
    return '<rect x="0.5" y="0.5" width="' . ($w - 1) . '" height="' . ($h - 1) . '" rx="16" fill="' . $t['bg'] . '" stroke="' . $t['line'] . '"/>'
        . '<circle cx="28" cy="33" r="5" fill="' . $t['lime'] . '"/>'
        . '<text x="42" y="38" font-size="15" font-weight="600" fill="' . $t['ink'] . '">' . e($title) . '</text>'
        . '<text x="24" y="60" font-size="12.5" fill="' . $t['muted'] . '">' . e($sub) . '</text>'
        . $inner
        . '<text x="24" y="' . ($h - 18) . '" font-size="11" fill="' . $t['muted'] . '">' . e($foot) . '</text>';
}

// ---- Data -----------------------------------------------------------------------------------------------------

$repos = array_values(array_filter(
    api("/users/$owner/repos?type=owner&per_page=100&sort=pushed", $token) ?? [],
    fn (array $r): bool => !$r['fork'] && !$r['archived'] && !$r['private'] && !in_array(strtolower($r['name']), $skip, true),
));
if (!$repos) { throw new RuntimeException('No public repositories found'); }

$bytes = []; $releases = 0; $ciOk = 0; $ciAll = 0; $turkish = 0; $last = '';
foreach ($repos as $r) {
    $name = $r['name'];
    foreach (api("/repos/$owner/$name/languages", $token) ?? [] as $lang => $n) { $bytes[$lang] = ($bytes[$lang] ?? 0) + $n; }
    $releases += count(array_filter(api("/repos/$owner/$name/releases?per_page=100", $token) ?? [], fn ($x) => !$x['draft'] && !$x['prerelease']));
    $runs = api("/repos/$owner/$name/actions/runs?branch=" . rawurlencode($r['default_branch']) . '&status=completed&exclude_pull_requests=true&per_page=1', $token);
    if (($runs['total_count'] ?? 0) > 0) { $ciAll++; $ciOk += $runs['workflow_runs'][0]['conclusion'] === 'success' ? 1 : 0; }
    $turkish += api("/repos/$owner/$name/contents/README.tr.md", $token) !== null ? 1 : 0;
    $last = max($last, $r['pushed_at']);
}
arsort($bytes);
$total = array_sum($bytes);
$langs = array_slice($bytes, 0, 5, true);
if (count($bytes) > 5) { $langs['Other'] = $total - array_sum($langs); }
$updated = (new DateTimeImmutable($last))->format('j M Y');
$count = count($repos);

// ---- Cards ----------------------------------------------------------------------------------------------------

if (!is_dir($out)) { mkdir($out); }
$files = [];
foreach (THEMES as $theme => $t) {
    $dark = $theme === 'dark';

    // Languages: one stacked bar and a two-column legend
    $w = 430; $h = 236; $x = 24.0; $bar = ''; $legend = ''; $i = 0;
    $bar .= '<clipPath id="b"><rect x="24" y="80" width="382" height="10" rx="5"/></clipPath><g clip-path="url(#b)"><rect x="24" y="80" width="382" height="10" fill="' . $t['track'] . '"/>';
    foreach ($langs as $lang => $n) {
        $share = $total ? $n / $total : 0;
        $color = LANG_COLORS[$lang] ?? ($dark ? '#6e717a' : '#a3a6ad');
        $bar .= sprintf('<rect x="%.2f" y="80" width="%.2f" height="10" fill="%s"/>', $x, 382 * $share, $color);
        $x += 382 * $share;
        $lx = 24 + ($i % 2) * 196; $ly = 120 + intdiv($i, 2) * 24;
        $legend .= sprintf('<circle cx="%d" cy="%d" r="5" fill="%s"/>', $lx + 5, $ly - 4, $color)
            . sprintf('<text x="%d" y="%d" font-size="13" fill="%s">%s <tspan fill="%s">%s%%</tspan></text>', $lx + 16, $ly, $t['ink'], e($lang), $t['muted'], number_format($share * 100, 1));
        $i++;
    }
    $bar .= '</g>';
    $files["languages-$theme.svg"] = svg($w, $h, '', card($t, $w, $h, 'Languages', 'Share of code in our public repositories', $bar . $legend,
        "From GitHub's language data for $count repositories"), 'Languages in our public repositories: '
        . implode(', ', array_map(fn ($l, $n) => $l . ' ' . number_format($total ? $n / $total * 100 : 0, 1) . '%', array_keys($langs), $langs)));

    // Projects: four live counts
    $cells = [
        [(string) $count, 'public projects'],
        [(string) $releases, $releases === 1 ? 'release' : 'releases'],
        [$ciAll ? "$ciOk/$ciAll" : '–', 'test suites passing'],
        ["$turkish/$count", 'documented in Turkish'],
    ];
    $inner = '';
    foreach ($cells as $k => [$big, $label]) {
        $cx = 24 + ($k % 2) * 196; $cy = 78 + intdiv($k, 2) * 66;
        $inner .= sprintf('<rect x="%d" y="%d" width="186" height="58" rx="12" fill="%s"/>', $cx, $cy, $t['chip'])
            . sprintf('<text x="%d" y="%d" font-size="20" font-weight="700" fill="%s">%s</text>', $cx + 16, $cy + 27, $t['ink'], e($big))
            . sprintf('<text x="%d" y="%d" font-size="12" fill="%s">%s</text>', $cx + 16, $cy + 45, $t['muted'], e($label));
    }
    $files["projects-$theme.svg"] = svg($w, $h, '', card($t, $w, $h, 'Open source', 'Libraries and docs we publish', $inner, "Last update $updated"),
        "Open source: $count public projects, $releases releases, $ciOk of $ciAll test suites passing, $turkish of $count documented in Turkish");

    // Stack: two centred rows of chips that fit their labels
    $body = '';
    foreach (array_chunk(STACK, 6, true) as $row => $chips) {
        $widths = array_map(fn ($label) => 58 + (LABEL_W[$label] ?? (int) ceil(mb_strlen($label) * 7.6)), $chips);
        $cx = (880 - array_sum($widths) - 10 * (count($chips) - 1)) / 2; $cy = $row * 54;
        foreach ($chips as $slug => $label) {
            preg_match('~<path d="([^"]+)"~', (string) file_get_contents(__DIR__ . "/icons/$slug.svg"), $m);
            $color = $slug === 'nextdotjs' && $dark ? $t['ink'] : ($slug === 'javascript' ? BRAND[$slug] : legible(BRAND[$slug], $t['chip'], $dark));
            $backing = $slug === 'javascript' ? '<rect x="1" y="1" width="22" height="22" fill="#141519"/>' : '';
            $body .= sprintf('<rect x="%.1f" y="%.1f" width="%d" height="43" rx="21.5" fill="%s" stroke="%s"/>', $cx + 0.5, $cy + 0.5, $widths[$slug] - 1, $t['chip'], $t['line'])
                . sprintf('<svg x="%.1f" y="%d" width="20" height="20" viewBox="0 0 24 24">%s<path fill="%s" d="%s"/></svg>', $cx + 14, $cy + 12, $backing, $color, $m[1])
                . sprintf('<text x="%.1f" y="%d" font-size="14" font-weight="500" fill="%s">%s</text>', $cx + 44, $cy + 27, $t['ink'], e($label));
            $cx += $widths[$slug] + 10;
        }
    }
    $files["stack-$theme.svg"] = svg(880, 98, '', $body, 'Technologies we work with: ' . implode(', ', STACK));

    // Services: a label and five phrases, one at a time (static under reduced motion)
    $n = count(SERVICES); $step = 3; $cycle = $n * $step;
    $css = sprintf('.s{opacity:0;animation:s %ds infinite}@keyframes s{0%%{opacity:0;transform:translateY(12px)}%.1f%%{opacity:1;transform:none}%.1f%%{opacity:1;transform:none}%.1f%%{opacity:0;transform:translateY(-12px)}100%%{opacity:0}}', $cycle, 100 / $n * 0.15, 100 / $n * 0.85, 100 / $n)
        . '@media (prefers-reduced-motion:reduce){.s{animation:none}.s0{opacity:1}}';
    $body = '<rect x="374" y="8" width="132" height="26" rx="13" fill="' . $t['lime'] . '"/>'
        . '<text x="440" y="25.5" font-size="11.5" font-weight="600" letter-spacing="1.6" text-anchor="middle" fill="#141519">WHAT WE DO</text>';
    foreach (SERVICES as $k => $phrase) {
        $body .= sprintf('<text class="s s%d" style="animation-delay:%ds" x="440" y="76" font-size="30" font-weight="600" letter-spacing="-0.6" text-anchor="middle" fill="%s">%s</text>', $k, $k * $step, $t['ink'], e($phrase));
    }
    $files["services-$theme.svg"] = svg(880, 92, $css, $body, 'What we do: ' . implode(', ', SERVICES));
}

foreach ($files as $name => $svg) {
    $path = "$out/$name";
    if (!is_file($path) || file_get_contents($path) !== $svg) { file_put_contents($path, $svg); echo "updated cards/$name\n"; }
}
echo "$count repositories, " . count($bytes) . " languages, $releases releases, CI $ciOk/$ciAll, Turkish docs $turkish/$count\n";
