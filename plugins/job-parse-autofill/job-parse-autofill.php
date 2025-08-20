<?php
/**
 * Plugin Name: Job Parse Autofill
 * Description: Paste a job posting URL; server fetches the page, extracts structured fields via OpenAI, and auto-fills your Short.io Link Maker form. Keeps preview after page reload.
 * Version: 1.6.2
 * Author: David Bogar
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

class JobParseAutofill {
  const OPT_KEY        = 'jobparse_openai_key';
  const REST_NS        = 'jobparse/v1';
  const ROUTE_EXTRACT  = '/extract';
  const ROUTE_DIAG     = '/diag';

  public function __construct() {
    add_action('admin_menu',         [$this, 'add_settings_page']);
    add_action('admin_init',         [$this, 'register_settings']);
    add_action('rest_api_init',      [$this, 'register_rest']);
    add_shortcode('jobparse',        [$this, 'shortcode']);
    add_action('wp_enqueue_scripts', [$this, 'register_assets']);
  }

  /* ---------------- Admin settings ---------------- */

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
        <li>Put the extractor shortcode <code>[jobparse]</code> on the same page as your Short.io Link Maker form shortcode <code>[shortio_link_form]</code> (extractor above the form).</li>
        <li>Paste a job URL and click <em>Extract fields</em>. It fills: <code>company</code>, <code>skill1..skill5</code>, <code>mytitle</code>, <code>yourtitle</code>, <code>slug</code>.</li>
      </ol>
    </div>
  <?php }

  /* ---------------- REST API ---------------- */

  public function register_rest() {
    register_rest_route(self::REST_NS, self::ROUTE_EXTRACT, [
      'methods'  => 'POST',
      'callback' => [$this, 'handle_extract'],
      'permission_callback' => function(WP_REST_Request $req) {
        return is_user_logged_in() && wp_verify_nonce($req->get_header('X-WP-Nonce'), 'wp_rest');
      }
    ]);

    register_rest_route(self::REST_NS, self::ROUTE_DIAG, [
      'methods'  => 'POST',
      'callback' => [$this, 'handle_diag'],
      'permission_callback' => function(WP_REST_Request $req) {
        return is_user_logged_in() && wp_verify_nonce($req->get_header('X-WP-Nonce'), 'wp_rest');
      }
    ]);
  }

  public function handle_diag(WP_REST_Request $req) {
    $out = [];
    $out['logged_in'] = is_user_logged_in();
    $out['nonce_ok']  = (bool) wp_verify_nonce($req->get_header('X-WP-Nonce'), 'wp_rest');
    $out['openai_key_present'] = (bool) get_option(self::OPT_KEY, '');

    $m = wp_remote_get('https://api.openai.com/v1/models', ['timeout'=>10]);
    $out['openai_http'] = is_wp_error($m) ? $m->get_error_message() : wp_remote_retrieve_response_code($m);

    $url = (string)$req->get_param('url');
    if ($url) {
      $r = wp_remote_get($url, [
        'timeout'=>10, 'redirection'=>5,
        'headers'=>[
          'User-Agent'=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36',
          'Accept'=>'text/html,application/json;q=0.9,*/*;q=0.8'
        ]
      ]);
      $out['url_http'] = is_wp_error($r) ? $r->get_error_message() : wp_remote_retrieve_response_code($r);
      $out['url_ct']   = is_wp_error($r) ? null : wp_remote_retrieve_header($r, 'content-type');
      $out['url_len']  = is_wp_error($r) ? null : strlen(wp_remote_retrieve_body($r));
    }

    return new WP_REST_Response($out, 200);
  }

  public function handle_extract(WP_REST_Request $req) {
    try {
      $url = trim((string)$req->get_param('url'));
      if (!$url) return new WP_Error('no_url', 'Missing URL', ['status'=>400]);

      $api_key = get_option(self::OPT_KEY, '');
      if (!$api_key) return new WP_Error('no_key', 'OpenAI key not configured', ['status'=>500]);

      $fetched = $this->fetch_url_text($url);
      if (is_wp_error($fetched)) {
        return new WP_REST_Response([
          'stage'=>'fetch', 'ok'=>false,
          'error'=>$fetched->get_error_code(),
          'message'=>$fetched->get_error_message()
        ], 200);
      }

      // Structured outputs schema
      $schema_object = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
          'company_name'     => ['type' => 'string'],
          'salary_range'     => ['type' => 'string'],
          'top_skills'       => ['type' => 'array', 'minItems'=>5,  'maxItems'=>5,  'items'=>['type'=>'string']],
          'more_skills'      => ['type' => 'array', 'minItems'=>10, 'maxItems'=>10, 'items'=>['type'=>'string']],
          'company_homepage' => ['type' => 'string'],
          'job_title'        => ['type' => 'string'],
          'summary'          => ['type' => 'string']
        ],
        'required' => ['company_name','salary_range','top_skills','more_skills','company_homepage','job_title','summary']
      ];

      $domain_hint = parse_url($fetched['url'], PHP_URL_HOST);
      $prompt =
        "You are extracting fields from a job posting web page.\n".
        "Source URL: {$fetched['url']}\n".
        "Source Domain: {$domain_hint}\n".
        "Page Title: {$fetched['title']}\n".
        "Meta Description: {$fetched['meta_desc']}\n\n".
        "TASKS:\n".
        "1) company_name\n2) salary_range (empty if none)\n3) top_skills (exactly 5)\n4) more_skills (exactly 10)\n".
        "5) company_homepage (root if company domain)\n6) job_title\n7) summary (<= 25 words)\n\n".
        "TEXT CONTENT START\n{$fetched['text']}\nTEXT CONTENT END\n";

      $body = [
        'model' => 'gpt-4o-mini',
        'input' => $prompt,
        'text'  => [
          'format' => [
            'type'   => 'json_schema',
            'name'   => 'job_fields',
            'schema' => $schema_object,
            'strict' => true
          ]
        ]
      ];

      $resp = wp_remote_post('https://api.openai.com/v1/responses', [
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
          'Content-Type'  => 'application/json'
        ],
        'body'       => wp_json_encode($body),
        'timeout'    => 25,
        'httpversion'=> '1.1',
        'redirection'=> 3,
      ]);

      if (is_wp_error($resp)) {
        return new WP_REST_Response([
          'stage'=>'openai','ok'=>false,'error'=>'openai_error',
          'message'=>$resp->get_error_message()
        ], 200);
      }

      $status = wp_remote_retrieve_response_code($resp);
      $raw    = wp_remote_retrieve_body($resp);

      if ($status < 200 || $status >= 300) {
        return new WP_REST_Response([
          'stage'=>'openai','ok'=>false,'error'=>'openai_http_'.$status,
          'body_excerpt'=>substr((string)$raw, 0, 800)
        ], 200);
      }

      $json = json_decode($raw, true);
      $parsed = $json['output'][0]['content'][0]['json']
             ?? $this->maybe_decode_json($json['output'][0]['content'][0]['text'] ?? null)
             ?? $this->maybe_decode_json($json['output_text'] ?? null)
             ?? $this->maybe_decode_json($json['choices'][0]['message']['content'] ?? null);

      if (!is_array($parsed)) {
        return new WP_REST_Response([
          'stage'=>'parse','ok'=>false,'error'=>'bad_parse',
          'body_excerpt'=>substr((string)$raw, 0, 800)
        ], 200);
      }

      $arrN = function($arr, $n){
        $arr = is_array($arr) ? array_map('strval', $arr) : [];
        $arr = array_slice($arr, 0, $n);
        while (count($arr) < $n) $arr[] = '';
        return array_values($arr);
      };

      $homepage = (string)($parsed['company_homepage'] ?? '');
      if ($homepage === '' && $domain_hint) {
        $scheme = parse_url($fetched['url'], PHP_URL_SCHEME) ?: 'https';
        $homepage = $scheme . '://' . $domain_hint . '/';
      }

      return new WP_REST_Response([
        'stage'=>'done','ok'=>true,
        'company_name'     => (string)($parsed['company_name'] ?? ''),
        'salary_range'     => (string)($parsed['salary_range'] ?? ''),
        'top_skills'       => $arrN($parsed['top_skills'] ?? [], 5),
        'more_skills'      => $arrN($parsed['more_skills'] ?? [], 10),
        'company_homepage' => $homepage,
        'job_title'        => (string)($parsed['job_title'] ?? ''),
        'summary'          => mb_substr((string)($parsed['summary'] ?? ''), 0, 180)
      ], 200);

    } catch (Throwable $e) {
      return new WP_REST_Response([
        'stage'=>'fatal','ok'=>false,'error'=>'exception',
        'message'=>$e->getMessage()
      ], 200);
    }
  }

  private function maybe_decode_json($candidate) {
    if (is_array($candidate)) return $candidate;
    if (is_string($candidate)) {
      $try = json_decode($candidate, true);
      if (is_array($try)) return $try;
    }
    return null;
  }

  /* ---------------- Fetcher: HTML or JSON ---------------- */

  private function fetch_url_text($url) {
    $url = esc_url_raw(trim($url));
    if (!$url || !preg_match('~^https?://~i', $url)) {
      return new WP_Error('bad_url', 'Invalid URL (must start with http/https).', ['status' => 400]);
    }

    $resp = wp_remote_get($url, [
      'timeout' => 20,
      'redirection' => 5,
      'headers' => [
        'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36 JobParseWP',
        'Accept'          => 'text/html,application/json;q=0.9,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.9',
        'Referer'         => $url
      ]
    ]);

    if (is_wp_error($resp)) {
      return new WP_Error('fetch_failed', $resp->get_error_message(), ['status' => 502]);
    }

    $code = wp_remote_retrieve_response_code($resp);
    if ($code < 200 || $code >= 300) {
      return new WP_Error('bad_status', 'URL returned HTTP '.$code, ['status' => 502]);
    }

    $ctype = (string) wp_remote_retrieve_header($resp, 'content-type');
    $body  = wp_remote_retrieve_body($resp);
    if (!$body) {
      return new WP_Error('empty_body', 'Empty response body.', ['status' => 502]);
    }

    $title = '';
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $body, $m)) $title = wp_strip_all_tags($m[1]);
    $meta_desc = '';
    if (preg_match('#<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']+)#i', $body, $m)) $meta_desc = wp_strip_all_tags($m[1]);

    $looks_json = (stripos($ctype, 'json') !== false) || preg_match('/^\s*[\{\[]/s', $body);
    if ($looks_json) {
      $j = json_decode($body, true);
      if (is_array($j)) {
        $candidates = [];
        $it = new RecursiveIteratorIterator(new RecursiveArrayIterator($j));
        foreach ($it as $k => $v) {
          if (is_string($v) && strlen($v) > 0) {
            $lk = strtolower((string)$k);
            if (
              strpos($lk,'description') !== false ||
              strpos($lk,'content') !== false ||
              strpos($lk,'body') !== false ||
              strpos($lk,'responsibilit') !== false ||
              strpos($lk,'qualif') !== false ||
              strpos($lk,'summary') !== false ||
              strpos($lk,'title') !== false
            ) {
              $candidates[] = $v;
            }
          }
        }
        if ($candidates) {
          $text = implode("\n\n", array_unique($candidates));
          $text = wp_strip_all_tags($text);
          $text = preg_replace('/[ \t]+/',' ', $text);
          $text = preg_replace('/\s{2,}/', "\n", trim($text));
          if (mb_strlen($text) > 300000) $text = mb_substr($text, 0, 300000);
          return [
            'url'       => $url,
            'title'     => $title,
            'meta_desc' => $meta_desc,
            'text'      => $text
          ];
        }
      }
    }

    $html = $body;
    $html = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $html);
    $html = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $html);
    $html = preg_replace('#<!--.*?-->#s', ' ', $html);

    $text = wp_strip_all_tags($html);
    $text = preg_replace('/[ \t]+/',' ', $text);
    $text = preg_replace('/\s{2,}/', "\n", trim($text));
    if (mb_strlen($text) > 300000) $text = mb_substr($text, 0, 300000);

    if ($ctype && stripos($ctype, 'text/html') === false) {
      if (stripos(ltrim($body), '<!DOCTYPE') === false && stripos(ltrim($body), '<html') === false && !$looks_json) {
        return new WP_Error('not_html', 'Content is not HTML/JSON.', ['status' => 415]);
      }
    }

    return [
      'url'       => $url,
      'title'     => $title,
      'meta_desc' => $meta_desc,
      'text'      => $text
    ];
  }

  /* ---------------- Shortcode & assets ---------------- */

  public function register_assets() {
    wp_register_script('jobparse-inline', '', [], '1.6.2', true);
  }

  public function shortcode($atts = []) {
    if (!is_user_logged_in()) return '<div>Please log in to use the extractor.</div>';
    $nonce = wp_create_nonce('wp_rest');
    ob_start(); ?>
      <div id="jobparse-wrap" style="margin: 1rem 0;">
        <label for="job_url" style="font-weight:600; display:block; margin-bottom:.25rem;">Job posting URL</label>
        <input id="job_url" type="url" placeholder="https://example.com/careers/job-id" style="width:100%;"/>

        <div style="margin-top:.5rem; display:flex; gap:.75rem; align-items:center; flex-wrap:wrap;">
          <button id="extract_btn" type="button">Extract fields</button>
          <button id="diag_btn" type="button" title="Connectivity check">Diag</button>

          <label style="display:flex; align-items:center; gap:.35rem;">
            <input id="jp_autosubmit" type="checkbox" checked />
            <span>Auto-create Short.io link after fill</span>
          </label>

          <a href="#" id="jp_clear" style="margin-left:.5rem;">Clear preview</a>

          <span id="extract_status" style="line-height:32px;"></span>
        </div>

        <div id="jobparse-preview" style="margin-top:1rem; display:none;">
          <div><strong>Company:</strong> <span data-k="company_name"></span></div>
          <div><strong>Job Title:</strong> <span data-k="job_title"></span></div>
          <div><strong>Salary:</strong> <span data-k="salary_range"></span></div>
          <div><strong>Homepage:</strong> <a href="#" target="_blank" rel="noopener" data-k="company_homepage_link"></a></div>
          <div><strong>Summary:</strong> <span data-k="summary"></span></div>
          <div><strong>Top Skills:</strong> <span data-k="top_skills"></span></div>
          <div><strong>More Skills:</strong> <span data-k="more_skills"></span></div>
        </div>
      </div>

      <script>
      (function(){
        const log = (...a)=>{ if (window.jobparseDebug) console.log('[jobparse]', ...a); };
        const nonce = <?php echo json_encode($nonce); ?>;
        const STORE_KEY = 'jobparse.last';   // holds {data, jobUrl, autosubmitOnce, submitted}
        const PREVIEW_TTL_MS = 15*60*1000;   // keep preview for 15 minutes

        // Persisted toggle
        const toggle = document.getElementById('jp_autosubmit');
        if (toggle) {
          const saved = localStorage.getItem('jp_autosubmit');
          if (saved != null) toggle.checked = saved === '1';
          toggle.addEventListener('change', () => localStorage.setItem('jp_autosubmit', toggle.checked ? '1' : '0'));
        }

        // Helpers
        const $ = (sel, root=document)=> root.querySelector(sel);
        function resolveEl(target, root=document){
          if (!target) return null;
          if (target instanceof Element) return target;
          if (typeof target === 'string') return $(target, root);
          return null;
        }
        function setVal(target, value){
          const el = resolveEl(target);
          if (!el) { console.warn('[jobparse] setVal: not found ->', target); return; }
          if ('value' in el) el.value = value ?? '';
          else el.textContent = value ?? '';
          el.dispatchEvent(new Event('input',  { bubbles:true }));
          el.dispatchEvent(new Event('change', { bubbles:true }));
        }
        function setText(target, value){
          const el = resolveEl(target);
          if (!el) { console.warn('[jobparse] setText: not found ->', target); return; }
          el.textContent = value ?? '';
        }
        function slugify(str){
          return (str || '')
            .toString().normalize('NFKD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-zA-Z0-9]+/g, '-').replace(/^-+|-+$/g, '').toLowerCase();
        }
        function sleep(ms){ return new Promise(r => setTimeout(r, ms)); }

        // Your Short.io Link Maker form fields
        const MAP = {
          company:  'input[name="company"]',
          skills: [
            'input[name="skill1"]',
            'input[name="skill2"]',
            'input[name="skill3"]',
            'input[name="skill4"]',
            'input[name="skill5"]'
          ],
          mytitle:   'input[name="mytitle"]',
          yourtitle: 'input[name="yourtitle"]',
          slug:      'input[name="slug"]'
        };

        function locateYourForm(){
          let form = document.querySelector('form:has(input[name="company"])');
          if (!form) {
            const candidate = document.querySelector('input[name="company"]');
            if (candidate) form = candidate.closest('form');
          }
          return form || null;
        }
        function waitForYourForm(timeoutMs = 10000){
          return new Promise(resolve => {
            const f = locateYourForm();
            if (f) return resolve(f);
            const obs = new MutationObserver(()=>{
              const ff = locateYourForm();
              if (ff) { obs.disconnect(); resolve(ff); }
            });
            obs.observe(document.documentElement, {childList:true, subtree:true});
            setTimeout(()=>{ obs.disconnect(); resolve(locateYourForm()); }, timeoutMs);
          });
        }

        function companyFallback(data){
          let fallbackCompany = '';
          try {
            const source = (data.company_homepage || '') || (window.lastJobUrl || '');
            if (source) {
              const u = new URL(source);
              const parts = u.hostname.replace(/^www\./,'').split('.').filter(Boolean);
              fallbackCompany = (parts.slice(-2, -1)[0] || parts[0] || '').replace(/[-_]/g, ' ');
            }
          } catch {}
          return fallbackCompany;
        }

        function fillYourForm(form, data){
          const comp = (data.company_name || '').trim() || companyFallback(data);
          setVal(MAP.company, comp);
          const skills = Array.isArray(data.top_skills) ? data.top_skills : [];
          for (let i=0;i<5;i++) setVal(MAP.skills[i], skills[i] || '');
          setVal(MAP.mytitle,   data.job_title || '');
          setVal(MAP.yourtitle, data.job_title || '');
          setVal(MAP.slug,      slugify(comp || ''));
        }

        function showPreview(data){
          const wrap = document.getElementById('jobparse-preview');
          if (!wrap) return;
          wrap.style.display = 'block';
          setText('[data-k="company_name"]', data.company_name || companyFallback(data));
          setText('[data-k="job_title"]', data.job_title || '');
          setText('[data-k="salary_range"]', data.salary_range || '');
          setText('[data-k="summary"]', data.summary || '');
          const linkNode = document.querySelector('[data-k="company_homepage_link"]');
          if (linkNode) {
            const url = (data.company_homepage || '').trim();
            linkNode.textContent = url || '';
            if (url) { linkNode.href = url; } else { linkNode.removeAttribute('href'); }
          }
          setText('[data-k="top_skills"]',  (data.top_skills  || []).filter(Boolean).join(', '));
          setText('[data-k="more_skills"]', (data.more_skills || []).filter(Boolean).join(', '));
        }

        function loadPersisted() {
          try {
            const raw = sessionStorage.getItem(STORE_KEY);
            if (!raw) return null;
            const obj = JSON.parse(raw);
            if (!obj || !obj.data) return null;
            // TTL
            if (obj.ts && (Date.now() - obj.ts > PREVIEW_TTL_MS)) {
              sessionStorage.removeItem(STORE_KEY);
              return null;
            }
            return obj;
          } catch { return null; }
        }
        function savePersisted(payload) {
          try { sessionStorage.setItem(STORE_KEY, JSON.stringify(payload)); } catch {}
        }
        function clearPersisted() { try { sessionStorage.removeItem(STORE_KEY); } catch {} }

        async function maybeSubmitYourForm(form, payload){
          const auto = document.getElementById('jp_autosubmit')?.checked;
          if (!auto || !form) return;

          // Avoid re-submitting after reload
          if (payload) {
            payload.autosubmitOnce = true;
            payload.submitted = true;
            savePersisted(payload);
          }

          await sleep(60);
          const missing = Array.from(form.querySelectorAll('input[required],textarea[required],select[required]'))
            .filter(el => !String(el.value || '').trim());
          if (missing.length) { missing[0].focus(); return; }

          const btn = form.querySelector('button[type="submit"], input[type="submit"]');
          if (btn) { btn.click(); return; }
          form.requestSubmit ? form.requestSubmit() : form.submit();
        }

        // Rehydrate on page load (after a POST reload)
        (async function rehydrateOnLoad(){
          const persisted = loadPersisted();
          if (!persisted) return;

          // Always re-show preview
          showPreview(persisted.data);

          // If we already submitted once, just keep the preview and fill the form again for convenience
          const form = await waitForYourForm(5000);
          if (form) fillYourForm(form, persisted.data);

          // If it wasn't submitted (e.g., you unchecked auto), keep it around; else, we can keep it until user clears
        })();

        // Clear preview link
        const clearBtn = document.getElementById('jp_clear');
        if (clearBtn) {
          clearBtn.addEventListener('click', (e)=>{
            e.preventDefault();
            clearPersisted();
            const wrap = document.getElementById('jobparse-preview');
            if (wrap) { wrap.style.display = 'none'; wrap.querySelectorAll('[data-k]').forEach(n=>n.textContent=''); }
          });
        }

        async function extract() {
          const status = document.getElementById('extract_status');
          const url = (document.getElementById('job_url')?.value || '').trim();
          if (!url) { alert('Enter a job posting URL.'); return; }
          window.lastJobUrl = url;

          status.textContent = 'Fetching & extracting…';
          try {
            const res = await fetch('<?php echo esc_url_raw( rest_url(self::REST_NS . self::ROUTE_EXTRACT) ); ?>', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': <?php echo json_encode($nonce); ?> },
              credentials: 'same-origin',
              body: JSON.stringify({ url })
            });
            if (!res.ok) {
              let details=''; const txt=await res.text().catch(()=> '');
              try { const j=JSON.parse(txt); details=j.message||j.code||txt; if(j.data?.status) details+=` (HTTP ${j.data.status})`; if(j.raw) details+=` | raw: ${JSON.stringify(j.raw).slice(0,400)}`; }
              catch { details = (txt||'').slice(0,700); }
              throw new Error(details || `Extractor failed (HTTP ${res.status})`);
            }
            const data = await res.json();

            if (!data.ok && data.stage) {
              alert(`Stage ${data.stage} error: ${data.error || data.message || 'unknown'}\n` + (data.body_excerpt || ''));
              status.textContent = '';
              return;
            }

            // Show preview immediately and persist before any submit
            showPreview(data);
            const payload = { data, jobUrl: url, ts: Date.now(), autosubmitOnce: false, submitted: false };
            savePersisted(payload);

            // Fill Short.io form
            const form = await waitForYourForm(10000);
            if (!form) {
              status.textContent = 'Extracted (no Short.io form found).';
              return;
            }
            fillYourForm(form, data);
            status.textContent = 'Filled.';

            // Auto-submit once, while keeping preview persistent across reload
            await maybeSubmitYourForm(form, payload);

          } catch (e) {
            console.error(e);
            status.textContent = '';
            alert(e.message || 'Sorry — could not extract fields.');
          }
        }

        async function diag() {
          const status = document.getElementById('extract_status');
          const url = (document.getElementById('job_url')?.value || '').trim();
          status.textContent = 'Diagnosing…';
          try {
            const res = await fetch('<?php echo esc_url_raw( rest_url(self::REST_NS . self::ROUTE_DIAG) ); ?>', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': <?php echo json_encode($nonce); ?> },
              credentials: 'same-origin',
              body: JSON.stringify({ url })
            });
            const data = await res.json();
            console.log('jobparse/diag →', data);
            alert(JSON.stringify(data, null, 2).slice(0, 1500));
            status.textContent = '';
          } catch (e) {
            console.error(e);
            status.textContent = '';
            alert('Diag failed');
          }
        }

        document.addEventListener('click', function(e){
          if (e.target && e.target.id === 'extract_btn') extract();
          if (e.target && e.target.id === 'diag_btn') diag();
        });
      })();
      </script>
    <?php
    return ob_get_clean();
  }
}

new JobParseAutofill();
