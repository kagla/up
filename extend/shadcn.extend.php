<?php
if (!defined('_GNUBOARD_')) exit; // 개별 페이지 접근 불가

/**
 * gnuboard5 · shadcn/ui 반응형 + 다크모드 PoC
 * -------------------------------------------------
 * 코어 파일은 단 한 줄도 수정하지 않는다.
 *  - CSS 교체        : run_replace('head_css_url') 훅
 *  - viewport / 테마 : run_event('pre_head') 에서 $config['cf_add_meta'] 에 주입
 *                      (head.sub.php 가 <head> 안에서 그대로 echo 한다)
 *  - 토글 버튼       : run_event('tail_sub') 훅
 *
 * 1차 적용 범위: 메인(index) + 게시판(bbs/board.php). 그 외는 순정 유지.
 */

define('G5_SHADCN_ENABLE', true);

if (G5_SHADCN_ENABLE && shadcn_in_scope()) {
    add_event('tail_sub', 'shadcn_theme_toggle');
    add_event('tail_sub', 'shadcn_se2_dark');
    add_replace('head_css_url',        'shadcn_head_css_url',  G5_HOOK_DEFAULT_PRIORITY, 2);
    add_replace('html_process_css_files', 'shadcn_filter_css', G5_HOOK_DEFAULT_PRIORITY, 1);

    shadcn_inject_head();
}

if (G5_SHADCN_ENABLE && shadcn_is_shop()) {
    shadcn_shop_hide_device_link();
}

/**
 * 적용 대상 페이지인지 판별한다.
 * extend 로드 시점이라 _INDEX_ 는 아직 없다 → SCRIPT_NAME 으로 판별한다.
 */
function shadcn_in_scope()
{
    static $scope = null;
    if ($scope !== null) return $scope;

    if (defined('G5_IS_ADMIN')) return $scope = false;

    $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
    $base   = basename($script);

    // 관리자 · 쇼핑몰은 제외
    if (preg_match('~/(adm|shop)/~', $script)) return $scope = false;

    // 게시판
    if ($base === 'board.php') return $scope = true;

    // bbs 화이트리스트 — shadcn.css 에 페이지별 CSS(§12·§13-2)를 갖추고
    // 실제 렌더링을 검증한 페이지만 추가한다. 스킨 CSS(style.css)가 제거되므로
    // 여기 추가 = 그 페이지 DOM 전체를 shadcn.css 가 책임진다는 뜻이다.
    // 검증 없이 정규식으로 /bbs/ 전체를 열지 말 것.
    $bbs_whitelist = array(
        'login.php',
        'register.php', 'register_form.php',
        'write.php', 'password_lost.php',
        'search.php', 'new.php', 'faq.php', 'current_connect.php',
    );
    if (preg_match('~/bbs/[^/]+$~', $script) && in_array($base, $bbs_whitelist, true)) {
        return $scope = true;
    }

    // 사이트 메인
    if ($base === 'index.php' && !preg_match('~/(bbs|plugin|extend)/~', $script)) {
        return $scope = true;
    }

    return $scope = false;
}

/**
 * 쇼핑몰(/shop/) 페이지인지 판별한다. shadcn_in_scope() 와 별개의 보조 스코프.
 */
function shadcn_is_shop()
{
    $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
    return (bool) preg_match('~/shop/~', $script);
}

/**
 * 쇼핑몰 푸터의 '모바일버전' 전환 링크를 숨긴다.
 * G5_SET_DEVICE='pc' 라 모바일 트리로 갈 수 없어 죽은 링크가 됐다
 * (theme/basic/shop/shop.tail.php:26 이 조건 없이 출력한다).
 * 쇼핑몰에는 shadcn.css 가 로드되지 않으므로(default_shop.css 유지) 테마
 * head.sub.php:48 이 <head> 안에서 그대로 echo 하는 $config['cf_add_meta'] 에
 * 한 줄짜리 <style> 을 얹는다 — 코어·테마 파일은 건드리지 않는다.
 * get_device_change_url()(lib/common.lib.php:4319)은 PC 트리에서 현재 URL 에
 * device=mobile 을 붙여 반환하므로 href 부분일치로 잡는다.
 */
function shadcn_shop_hide_device_link()
{
    global $config;

    $css = '<style>#ft a[href*="device=mobile"]{display:none}</style>' . PHP_EOL;
    $config['cf_add_meta'] = (isset($config['cf_add_meta']) ? $config['cf_add_meta'] : '') . $css;
}

/**
 * <head> 안에 viewport 메타 + FOUC 방지 테마 스크립트를 넣는다.
 * head.sub.php(코어/테마 공통)가 $config['cf_add_meta'] 를 그대로 echo 한다.
 */
function shadcn_inject_head()
{
    global $config;

    $head = '';

    // PC 트리는 viewport 메타를 출력하지 않는다 → 반응형의 1차 차단 요인
    if (!G5_IS_MOBILE) {
        $head .= '<meta name="viewport" content="width=device-width,initial-scale=1">' . PHP_EOL;
    }

    $head .= '<script>(function(){try{'
           . 'var q=new URLSearchParams(location.search).get("theme");'
           . 'if(q){localStorage.setItem("g5-theme",q);}'
           . 'var t=localStorage.getItem("g5-theme");'
           . 'if(t==="dark"||(!t&&matchMedia("(prefers-color-scheme:dark)").matches))'
           . 'document.documentElement.classList.add("dark");'
           . '}catch(e){}})();</script>' . PHP_EOL;

    $config['cf_add_meta'] = $head . (isset($config['cf_add_meta']) ? $config['cf_add_meta'] : '');
}

/**
 * 코어 기본 CSS(default.css / mobile.css)를 shadcn 토큰 CSS로 교체한다.
 * 쇼핑몰 CSS·관리자 CSS 는 건드리지 않는다.
 */
function shadcn_head_css_url($url, $g5_url)
{
    if (preg_match('~/css/(default|mobile)\.css~', $url)) {
        return G5_CSS_URL . '/shadcn.css?ver=' . G5_CSS_VER;
    }

    return $url;
}

/**
 * add_stylesheet() 로 뒤늦게 붙는 스킨 CSS 를 걷어낸다.
 * 이것들이 shadcn.css 뒤에 로드되면서 토큰 스타일을 덮어쓴다.
 * 아이콘 폰트(font-awesome)와 캐러셀은 남긴다.
 */
function shadcn_filter_css($links)
{
    if (!is_array($links)) return $links;

    $kept = array();
    foreach ($links as $link) {
        $tag = isset($link[1]) ? $link[1] : '';
        if (preg_match('~/skin/[^"\']*\.css~', $tag)) continue; // 코어·테마 스킨 CSS 제거
        $kept[] = $link;
    }

    return $kept;
}

/**
 * smarteditor2 를 다크모드에 동기화한다.
 *
 * 에디터 전체(툴바 포함)는 HuskyEZCreator.createInIFrame 이 만드는
 * SmartEditor2Skin.html iframe 안에 있고, 본문 캔버스는 그 안의
 * iframe#se2_iframe (smart_editor2_inputarea.html) 이다. 부모 CSS 는 어느
 * 쪽에도 닿지 않으므로, 같은 출처임을 이용해 두 문서에 <style> 을 주입하고
 * 부모 <html.dark> 상태를 각 문서의 html.se2dark 클래스로 복제한다.
 *
 *  - 툴바 크롬: 아이콘이 전부 스프라이트 이미지(btn_set.png 등)라 색상만으로는
 *    다크화가 불가능하다 → #smart_editor2 전체에 invert(1) hue-rotate(180deg).
 *  - 본문 iframe·색상 견본(팔레트 스와치)·사진 등 "실제 색이 의미인" 요소는
 *    같은 필터를 한 번 더 걸어 원래 색으로 되돌린다(invert 2회 = 항등).
 *  - 본문 캔버스는 별도 주입 CSS 로 페이지 토큰과 같은 색(#0a0a0a/#fafafa
 *    ≈ oklch 0.145/0.985)을 칠한다. 토글 전환은 MutationObserver 로 따라간다.
 *
 * 플러그인(plugin/editor/smarteditor2) 파일은 건드리지 않는다.
 */
function shadcn_se2_dark()
{
    global $config;

    if (!shadcn_in_scope()) return;
    if (!isset($config['cf_editor']) || $config['cf_editor'] !== 'smarteditor2') return;
    ?>
<script>
(function () {
    var root = document.documentElement;

    // 주의: 스킨 문서에는 color-scheme:dark 를 주지 않는다 — 네이티브 위젯
    // (textarea 등)이 어두워진 채 invert 되어 도로 밝아진다. 배경을 명시해
    // 칠하므로 color-scheme 불일치로 iframe 이 흰색 불투명이 되는 일도 없다.
    var SKIN_CSS =
        'html.se2dark{background:#0a0a0a}' +
        'html.se2dark body{background:#0a0a0a}' +
        'html.se2dark #smart_editor2{filter:invert(1) hue-rotate(180deg)}' +
        'html.se2dark #se2_iframe,' +
        'html.se2dark #smart_editor2 img,' +
        'html.se2dark .se2_pick_color li button span,' +
        'html.se2dark .selected_color,' +
        'html.se2dark .husky_se2m_cp_preview,' +
        'html.se2dark .se2_pre_color button,' +
        'html.se2dark .se2_background li button' +
        '{filter:invert(1) hue-rotate(180deg)}';

    var INPUT_CSS =
        'html.se2dark{color-scheme:dark;background:#0a0a0a}' +
        'html.se2dark body{background:#0a0a0a;color:#fafafa}';

    function ensure(doc, css) {
        if (!doc || !doc.documentElement) return;
        if (!doc.getElementById('shadcn_se2_dark')) {
            var st = doc.createElement('style');
            st.id = 'shadcn_se2_dark';
            st.textContent = css;
            (doc.head || doc.documentElement).appendChild(st);
        }
        doc.documentElement.classList.toggle('se2dark', root.classList.contains('dark'));
    }

    function sync() {
        var frames = document.querySelectorAll('iframe[src*="SmartEditor2Skin"]');
        for (var i = 0; i < frames.length; i++) {
            var sdoc = null;
            try { sdoc = frames[i].contentDocument; } catch (e) {}
            if (!sdoc) continue;
            ensure(sdoc, SKIN_CSS);
            var inner = sdoc.getElementById('se2_iframe');
            if (inner) {
                var idoc = null;
                try { idoc = inner.contentDocument; } catch (e) {}
                if (idoc) ensure(idoc, INPUT_CSS);
            }
        }
    }

    function start() {
        if (!document.querySelector('textarea.smarteditor2')) return;
        // 에디터·본문 iframe 은 비동기로 생기고 inputarea 문서는 교체되기도
        // 한다 → 초기 40초는 폴링으로 주입하고, 이후엔 토글 관찰자만 남긴다.
        var n = 0;
        var t = setInterval(function () {
            sync();
            if (++n >= 100) clearInterval(t);
        }, 400);
        new MutationObserver(sync).observe(root, { attributes: true, attributeFilter: ['class'] });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
</script>
    <?php
}

/**
 * 다크모드 토글 버튼을 헤더에 붙인다.
 */
function shadcn_theme_toggle()
{
    if (!shadcn_in_scope()) return;
    ?>
<script>
(function () {
    var root = document.documentElement;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.id = 'g5_theme_toggle';
    btn.setAttribute('aria-label', '다크모드 전환');
    btn.innerHTML =
        '<svg class="icon-moon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>' +
        '<svg class="icon-sun" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>';

    btn.addEventListener('click', function () {
        var dark = root.classList.toggle('dark');
        try { localStorage.setItem('g5-theme', dark ? 'dark' : 'light'); } catch (e) {}
    });

    function mount() {
        var host = document.querySelector('#hd_qnb') ||
                   document.querySelector('.hd_login') ||
                   document.querySelector('#hd_wrapper');
        (host || document.body).appendChild(btn);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }
})();
</script>
    <?php
}
