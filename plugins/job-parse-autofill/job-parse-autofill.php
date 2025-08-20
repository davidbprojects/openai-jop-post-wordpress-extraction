<?php
/**
 * Plugin Name: Job Parse Autofill
 * Description: Paste a job URL, server fetches the page, extracts structured fields via OpenAI, and auto-fills your form.
 * Version: 1.2.1
 * Author: David Bogar
 */

if (!defined('ABSPATH')) exit;

class JobParseAutofill {
  const OPT_KEY = 'jobparse_openai_key';
  const REST_NS = 'jobparse/v1';
  const REST_ROUTE = '/extract';

  public function __construct() {
    add_action('admin_menu', [$this, 'add_settings_page']);
    add_action('admin_init', [$this, 'register_settings']);
    add_action('rest_api_init', [$this, 'register_rest']);
    add_shortcode('jobparse', [$this, 'shortcode']);
    add_action('wp_enqueue_scripts', [$this, 'register_assets']);
  }

  /* ---------- Admin settings ---------- */

  public function add_settings_page() {
    add_options_page(
      'Job Parse Autofill',
      'Job Parse Autofill',
      'manage_options',
      'jobparse-autofill',
      [$this, 'render_settings_page']
    );
  }

  public function register_settings() {
    register_setting('jobparse_group', self::OPT_KEY);
    add_settings_section('jobparse_section', 'OpenAI Settings', function(){}, 'jobparse-autofill');
    add_settings_field(
      'jobparse_openai_key',
      'OpenAI API Key',
      [$this, 'render_api_key_field'],
      'jobparse-autofill',
      'jobparse_section'
    );
  }

  public function render_api_key_field() {
    $val = esc_attr(get_option(self::OPT_KEY, ''));
    echo '<input type="password" name="'.self::OPT_KEY.'" value="'.$val.'" style="width: 420px;" placeholder="sk-..."/>';
    echo '<p class="description">Stored server-side in WordPress options.</p>';
  }

  public function render_settings_page() { ?>
    <div class="wrap">
      <h1>Job Parse Autofill</h1>
      <form method="post" action="options.php">
        <?php
          settings_fields('jobparse_group');
          do_settings_sections('jobparse-autofill');
          submit_button();
        ?>
      </form>
      <hr/>
      <h2>How to use</h2>
      <ol>
        <li>Save your OpenAI key above.</li>
        <li>Add <code>[jobparse]</code> shortcode to the page with your form.</li>
        <li>Ensure your form has IDs: <code>#company_name</code>, <code>#company_title</code>, <code>#my_title</code>, <code>#skill_1..#skill_5</code>, <code>#shortpath</code>.</li>
        <li>Paste a job posting URL, click <em>Extract fields</em>.</li>
      </ol>
    </div>
  <?php }

  /* ---------- REST endpoint ---------- */

  public function register_rest() {
    register_rest_route(self::REST_NS, self::REST_ROUTE, [
      'methods'  => 'POST',
      'callback' => [$this, 'handle_extract'],
      'permission_callback' => function(WP_REST_Request $req) {
        return is_user_logged_in() && wp_verify_nonce($req->get_header('X-WP-Nonce'), 'wp_rest');
      }
    ]);
  }

  private function fetch_url_text($url) {
    // Validate URL & scheme
    $url = esc_url_raw(trim($url));
    if (!$url || !preg_match('~^https?://~i', $url)) {
      return new WP_Error('bad_url', 'Invalid URL (must start with http/https).', ['status' => 400]);
    }

    // Fetch with a “real” user agent; follow redirects
    $resp = wp_remote_get($url, [
      'timeout' => 25,
      'redirection' => 5,
      'headers' => [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
          .'(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 WordPress/JobParse'
      ]
    ]);

    if (is_wp_error($resp)) {
      return new WP_Error('fetch_failed', $resp->get_error_message(), ['status' => 502]);
    }

    $code = wp_remote_retrieve_response_code($resp);
    if ($code < 200 || $code >= 300) {
      return new WP_Error('bad_status', 'URL returned HTTP '.$code, ['status' => 502]);
    }

    $ctype = wp_remote_retrieve_header($resp, 'content-type');
    $body  = wp_remote_retrieve_body($resp);
    if (!$body) {
      return new WP_Error('empty_body', 'Empty response body.', ['status' => 502]);
    }

    // If it's not text/html, bail (you can expand to support PDFs, etc.)
    if ($ctype && stripos($ctype, 'text/html') === false) {
      // still try to parse as HTML if looks like HTML
      if (stripos(ltrim($body), '<!DOCTYPE') === false && stripos(ltrim($body), '<html') === false) {
        return new WP_Error('not_html', 'Content is not HTML.', ['status' => 415]);
      }
    }

    // Extract readable text: strip scripts/styles, tags, compress whitespace.
    $html = $body;

    // Quick scrubs
    $html = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $html);
    $html = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $html);
    $html = preg_replace('#<!--.*?-->#s', ' ', $html);

    // Pull title & meta description to help the model
    $title = '';
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) $title = wp_strip_all_tags($m[1]);
    $meta_desc = '';
    if (preg_match('#<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']+)#i', $html, $m)) $meta_desc = wp_strip_all_tags($m[1]);

    // Body text
    $text = wp_strip_all_tags($html);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\s{2,}/', "\n", trim($text));

    // Keep it to a sane size (OpenAI will do fine with a few thousand tokens)
    if (mb_strlen($text) > 300000) $text = mb_substr($text, 0, 300000);

    return [
      'url'        => $url,
      'title'      => $title,
      'meta_desc'  => $meta_desc,
      'text'       => $text
    ];
  }

  public function handle_extract(WP_REST_Request $req) {
    $url = (string)$req->get_param('url');
    if (!$url) return new WP_Error('no_url', 'Missing URL', ['status' => 400]);

    $api_key = get_option(self::OPT_KEY, '');
    if (!$api_key) return new WP_Error('no_key', 'OpenAI key not configured', ['status' => 500]);

    $fetched = $this->fetch_url_text($url);
    if (is_wp_error($fetched)) return $fetched;

    // Strict schema for expanded fields
    $schema = [
      'name' => 'job_fields',
      'schema' => [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['company_name','job_title','top_skills','more_skills','summary'],
        'properties' => [
          'company_name' => ['type' => 'string', 'description' => 'Company or organization name'],
          'salary_range' => ['type' => 'string', 'description' => 'Salary range as stated (e.g., $140k–$180k + bonus). If unavailable, return empty string.'],
          'top_skills' => [
            'type' => 'array', 'minItems' => 5, 'maxItems' => 5,
            'items' => ['type' => 'string', 'description' => 'One concise skill keyword/phrase']
          ],
          'more_skills' => [
            'type' => 'array', 'minItems' => 10, 'maxItems' => 10,
            'items' => ['type' => 'string', 'description' => 'Additional skills ordered by importance']
          ],
          'company_homepage' => ['type' => 'string', 'description' => 'Homepage URL if obvious; else empty string'],
          'job_title' => ['type' => 'string', 'description' => 'Title being hired for'],
          'summary' => ['type' => 'string', 'description' => 'Up to ~25 words summary of what the company is looking for']
        ]
      ]
    ];

    // Provide URL context so model can derive homepage if not obvious in text
    $domain_hint = parse_url($fetched['url'], PHP_URL_HOST);

    $prompt =
      "You are extracting fields from a job posting web page.\n".
      "Source URL: {$fetched['url']}\n".
      "Source Domain: {$domain_hint}\n".
      "Page Title: {$fetched['title']}\n".
      "Meta Description: {$fetched['meta_desc']}\n\n".
      "TASKS:\n".
      "1) company_name: concise name of the hiring company.\n".
      "2) salary_range: salary as written (e.g., \"$140k–$180k + bonus\"); if none, empty string.\n".
      "3) top_skills: EXACTLY 5 items, ordered by importance.\n".
      "4) more_skills: EXACTLY 10 items, ordered by importance (next 10 after the top 5).\n".
      "5) company_homepage: official homepage URL if obvious; else empty string. If not stated but the source appears to be the company's own domain, use its root URL.\n".
      "6) job_title: concise job title.\n".
      "7) summary: <= 25 words describing what they seek.\n\n".
      "TEXT CONTENT START\n{$fetched['text']}\nTEXT CONTENT END\n";

    $body = [
      'model' => 'gpt-4o-mini',
      'input' => $prompt,
      'response_format' => [
        'type' => 'json_schema',
        'json_schema' => $schema,
        'strict' => true
      ]
    ];

    $resp = wp_remote_post('https://api.openai.com/v1/responses', [
      'headers' => [
        'Authorization' => 'Bearer ' . $api_key,
        'Content-Type'  => 'application/json'
      ],
      'body'    => wp_json_encode($body),
      'timeout' => 60
    ]);

    if (is_wp_error($resp)) {
      return new WP_Error('openai_error', $resp->get_error_message(), ['status' => 502]);
    }

    $json = json_decode(wp_remote_retrieve_body($resp), true);
    $candidate = $json['output'][0]['content'][0]['text'] ?? ($json['output_text'] ?? ($json['choices'][0]['message']['content'] ?? null));
    $parsed = is_array($candidate) ? $candidate : json_decode((string)$candidate, true);

    if (!is_array($parsed)) {
      return new WP_Error('bad_parse', 'Could not parse OpenAI response', ['status' => 502, 'raw' => $json]);
    }

    // Normalize helpers
    $def_arr = function($arr, $n) {
      $arr = is_array($arr) ? array_map('strval', $arr) : [];
      $arr = array_slice($arr, 0, $n);
      while (count($arr) < $n) $arr[] = '';
      return array_values($arr);
    };

    // If homepage empty but same-domain, default to scheme://host
    $homepage = (string)($parsed['company_homepage'] ?? '');
    if ($homepage === '' && $domain_hint) {
      $scheme = parse_url($fetched['url'], PHP_URL_SCHEME) ?: 'https';
      $homepage = $scheme . '://' . $domain_hint . '/';
?>