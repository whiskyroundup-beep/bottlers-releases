<?php
/**
 * Whisky Roundup ボトラーズ最新リリース（世界版）v1.7.1
 * 使い方：プラグインとして有効化 → 固定ページに [bottlers_list]
 * データ：GitHub の data/releases.json（毎週自動更新）を1時間キャッシュ
 * 要件：REQUIREMENTS.md
 */

if ( ! defined( 'WR_BOTTLERS_JSON' ) ) {
	define( 'WR_BOTTLERS_JSON', 'https://raw.githubusercontent.com/whiskyroundup-beep/bottlers-releases/main/data/releases.json' );
}

// ページがキャッシュから配信されていても、1時間ごとにデータを取りに行く
add_action( 'wr_bottlers_hourly', 'wr_bottlers_get_data' );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'wr_bottlers_hourly' ) ) wp_schedule_event( time() + 300, 'hourly', 'wr_bottlers_hourly' );
} );

function wr_bottlers_get_data() {
	if ( isset( $_GET['wrb_refresh'] ) && current_user_can( 'manage_options' ) ) {
		delete_transient( 'wr_bottlers_data_v4' );
		delete_option( 'wr_bottlers_synced' );
	}
	$cache = get_transient( 'wr_bottlers_data_v4' );
	if ( $cache !== false ) {
		return $cache;
	}
	$res  = wp_remote_get( WR_BOTTLERS_JSON, array( 'timeout' => 10 ) );
	$data = null;
	if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
	}
	if ( ! is_array( $data ) || ! isset( $data['releases'] ) ) {
		$backup = get_option( 'wr_bottlers_backup_v4' );
		return $backup ? $backup : null;
	}
	set_transient( 'wr_bottlers_data_v4', $data, HOUR_IN_SECONDS );
	update_option( 'wr_bottlers_backup_v4', $data, false );
	wr_bottlers_sync_modified( $data );
	return $data;
}

/**
 * データの更新日が変わったら、ショートコードを置いた固定ページの「更新日」を合わせる
 * （サイトマップの lastmod や検索エンジンに、週次更新を伝えるため）
 */
function wr_bottlers_sync_modified( $data ) {
	if ( empty( $data['updated'] ) ) return;
	if ( get_option( 'wr_bottlers_synced' ) === $data['updated'] ) return;
	global $wpdb;
	$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'page' AND post_content LIKE '%[bottlers_list%'" );
	$local = $data['updated'] . ' ' . current_time( 'H:i:s' );
	$gmt   = get_gmt_from_date( $local );
	foreach ( $ids as $id ) {
		$wpdb->update( $wpdb->posts, array( 'post_modified' => $local, 'post_modified_gmt' => $gmt ), array( 'ID' => $id ) );
		clean_post_cache( $id );
	}
	update_option( 'wr_bottlers_synced', $data['updated'], false );
	wr_bottlers_purge_page_cache( $ids );
}

// ページキャッシュ（WP Fastest Cache のPC用・スマホ用など）を消して、更新をすぐ反映させる
function wr_bottlers_purge_page_cache( $ids = null ) {
	if ( null === $ids ) {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'page' AND post_content LIKE '%[bottlers_list%'" );
	}
	foreach ( (array) $ids as $id ) {
		if ( function_exists( 'wpfc_clear_post_cache_by_id' ) ) wpfc_clear_post_cache_by_id( $id );
		do_action( 'litespeed_purge_post', $id );
	}
	if ( ! function_exists( 'wpfc_clear_post_cache_by_id' ) && isset( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) {
		$GLOBALS['wp_fastest_cache']->deleteCache( false );
	}
}

function wr_bottlers_fmt_date( $d ) {
	if ( ! $d ) return '';
	$p = explode( '-', $d );
	if ( count( $p ) === 3 ) return intval( $p[0] ) . '年' . intval( $p[1] ) . '月' . intval( $p[2] ) . '日';
	if ( count( $p ) === 2 ) return intval( $p[0] ) . '年' . intval( $p[1] ) . '月';
	return $d . '年';
}

function wr_bottlers_price( $price, $fx ) {
	if ( empty( $price ) || empty( $price['amount'] ) ) return null;
	$sym  = array( 'GBP' => '£', 'USD' => '$', 'EUR' => '€', 'JPY' => '¥' );
	$c    = $price['currency'];
	$a    = $price['amount'];
	$main = ( isset( $sym[ $c ] ) ? $sym[ $c ] : $c . ' ' ) . number_format( $a, ( floor( $a ) == $a ) ? 0 : 2 );
	$jpy  = ( $c === 'JPY' ) ? $a : ( ! empty( $fx[ $c ] ) ? round( $a * $fx[ $c ], -2 ) : null );
	$yen  = ( $c !== 'JPY' && $jpy ) ? '約' . number_format( $jpy ) . '円' : '';
	$raw  = ( $c === 'JPY' ) ? $a : ( ! empty( $fx[ $c ] ) ? $a * $fx[ $c ] : null );
	return array( $main, $yen, $raw );
}

function wr_bottlers_region_color( $r ) {
	$m = array( 'アイラ' => '#33505e', 'スペイサイド' => '#7a4b22', 'ハイランド' => '#5b3d26', 'アイランズ' => '#35503f', 'ローランド' => '#6e5f33', 'キャンベルタウン' => '#523445', 'グレーン' => '#7d6c4c' );
	return isset( $m[ $r ] ) ? $m[ $r ] : '#630000';
}

function wr_bottlers_shortcode() {
	$data = wr_bottlers_get_data();
	if ( ! $data ) {
		return '<p>リリース情報を読み込めませんでした。時間をおいて再度ご確認ください。</p>';
	}
	$L     = $data['labels'];
	$fx    = isset( $data['fx'] ) ? $data['fx'] : array();
	$days  = isset( $data['archive_days'] ) ? intval( $data['archive_days'] ) : 90;
	$today = current_time( 'Y-m-d' );
	$bmap  = array();
	foreach ( $data['bottlers'] as $b ) $bmap[ $b['id'] ] = $b;

	$items = array();
	foreach ( $data['releases'] as $x ) {
		$base = $x['announced'] ? $x['announced'] : $x['first_seen'];
		if ( strlen( $base ) === 7 ) $base .= '-01';
		$age = ( strtotime( $today ) - strtotime( $base ) ) / 86400;
		$x['_base']     = $base;
		$x['_archived'] = ( $age > $days ) && empty( $x['jp_pending'] );
		$x['_new']      = $x['announced'] && $age <= 14;
		$x['_bottler']  = isset( $bmap[ $x['bottler_id'] ] ) ? $bmap[ $x['bottler_id'] ]['name'] : $x['bottler_id'];
		$items[]        = $x;
	}
	usort( $items, function ( $a, $b ) { return strcmp( $b['_base'], $a['_base'] ); } );

	$active     = array_filter( $items, function ( $x ) { return ! $x['_archived']; } );
	$archived   = count( $items ) - count( $active );
	$jp_order   = array( 'jp_official', 'jp_route', 'jp_parallel', 'jp_unknown', 'jp_none' );
	$jp_count   = array_count_values( array_column( $active, 'jp_status' ) );
	$bottlers   = array_unique( array_column( $items, '_bottler' ) );
	sort( $bottlers );
	$regions    = array_unique( array_filter( array_column( $items, 'region' ) ) );
	sort( $regions );
	$tier_b     = array();
	foreach ( $data['bottlers'] as $b ) if ( $b['tier'] === 'B' ) $tier_b[] = $b['name'];
	$n_with     = count( array_unique( array_column( $active, 'bottler_id' ) ) );
	$n_all      = count( $data['bottlers'] );
	$legend_rg  = array( 'アイラ', 'スペイサイド', 'ハイランド', 'アイランズ', 'ローランド', 'キャンベルタウン', 'グレーン' );

	ob_start();
	?>
<div class="wrb" id="wrb">
<style>
.wrb{--ink:#231710;--sub:#6f5e53;--line:#e8dfcf;--soft:#f7f3ea;--card:#fff;--brand:#630000;--cream:#eeebdd;--rose:#d8b6a4;--gold:#a87a0a;color:var(--ink);font-size:15px;line-height:1.65}
.wrb *{box-sizing:border-box}.wrb [hidden]{display:none!important}
.wrb a,.wrb a:hover{text-decoration:none;color:inherit;box-shadow:none}
.wrb p{margin:0}
.wrb-intro{margin:0 0 18px;display:grid;gap:8px}
#wrb .wrb-intro p{font-size:15px!important;line-height:1.8!important;margin:0!important;padding:0!important}
#wrb .wrb-notes{font-size:12px!important;line-height:1.7!important}
#wrb .wrb-notes li{font-size:12px!important;line-height:1.7!important}
#wrb p,#wrb li,#wrb dd,#wrb dt{letter-spacing:normal}
#wrb .wrb-help p,#wrb .wrb-small p{font-size:inherit!important;line-height:1.7!important;margin:0!important}
.wrb-notes{margin:4px 0 0!important;padding:0!important;list-style:none!important;display:grid;gap:2px;font-size:12px;color:var(--sub)}
.wrb-notes li{margin:0!important;padding:0 0 0 1.1em!important;position:relative;border:0!important;background:none!important}
.wrb-notes li::before{content:"※";position:absolute;left:0;top:0;background:none!important;width:auto!important;height:auto!important;border:0!important}
.wrb .wrb-notes a{color:var(--brand);text-decoration:underline}
.wrb-hero{background:var(--brand);color:#fff;border-radius:14px;padding:16px 20px;margin:0 0 12px;display:flex;flex-wrap:wrap;gap:10px 28px;align-items:flex-end}
.wrb-hero .k{font-size:12px;color:var(--rose)}
.wrb-hero .v{font-size:26px;font-weight:700;line-height:1.15;font-variant-numeric:tabular-nums}
.wrb-hero .v small{font-size:13px;font-weight:400;margin-left:3px;color:var(--cream)}
.wrb-hero .u{margin-left:auto;font-size:12px;color:var(--cream)}
.wrb-help{border:1px solid var(--line);border-radius:10px;background:var(--soft);margin:0 0 16px;font-size:13px}
.wrb-help summary{cursor:pointer;padding:10px 14px;font-weight:700;list-style:none;display:flex;justify-content:space-between}
.wrb-help summary::-webkit-details-marker{display:none}
.wrb-help summary::after{content:"＋";color:var(--brand)}
.wrb-help[open] summary::after{content:"－"}
.wrb-help .in{padding:0 14px 12px;display:grid;gap:10px}
.wrb-help dl{margin:0;display:grid;grid-template-columns:auto 1fr;gap:4px 10px;align-items:baseline}
.wrb-help dt{white-space:nowrap;display:flex;gap:6px;align-items:center;font-weight:700}
.wrb-help dd{margin:0;color:var(--sub)}
.wrb-help .h{display:block;font-size:13px;font-weight:700;margin:0 0 4px;color:var(--ink)}
.wrb-lg{display:inline-flex;align-items:center;gap:5px;margin:0 12px 4px 0;white-space:nowrap}
.wrb-lg::before{content:"";width:12px;height:12px;border-radius:3px;background:var(--tc)}
.wrb-ctrl{margin:0 0 14px}
.wrb-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 10px}
.wrb-tabs .lbl{width:100%;font-size:12px;color:var(--sub)}
.wrb-tab{border:1px solid var(--line);background:var(--card);color:var(--ink);border-radius:999px;padding:7px 13px;font-size:13px;cursor:pointer;line-height:1.2;display:inline-flex;align-items:center;gap:6px}
.wrb-tab .c{color:var(--sub);font-variant-numeric:tabular-nums}
.wrb-tab[aria-pressed="true"]{background:var(--ink);border-color:var(--ink);color:#fff}
.wrb-tab[aria-pressed="true"] .c{color:var(--rose)}
.wrb-more{display:none}
.wrb-filters{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}
.wrb-qw{margin:0 0 8px}
.wrb-filters select,.wrb-qw input[type=search]{width:100%;height:42px;border:1px solid var(--line);border-radius:8px;padding:0 10px;font-size:14px;background:var(--card);color:var(--ink);margin:0}
.wrb-result{display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:13px;color:var(--sub);padding:10px 0;border-top:1px solid var(--line);margin:0 0 12px}
.wrb-result b{color:var(--ink);font-size:15px}
.wrb-reset{border:0;background:none;color:var(--brand);font-size:13px;cursor:pointer;padding:0;text-decoration:underline}
.wrb-list{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.wrb-card{display:flex;flex-direction:column;background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden;transition:transform .15s,box-shadow .15s}
.wrb-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(35,23,16,.10)}
.wrb-card:focus-visible{outline:3px solid var(--gold);outline-offset:2px}
.wrb-card.is-arch{opacity:.75}
.wrb-media{position:relative;aspect-ratio:16/10;overflow:hidden;background:linear-gradient(160deg,var(--tc) 0%,#1a110b 100%)}
.wrb-media::before{content:"";position:absolute;right:-30px;top:-30px;width:140px;height:140px;border-radius:50%;border:1px solid rgba(255,255,255,.18)}
.wrb-rg{position:absolute;left:12px;top:10px;font-size:11px;color:#fff;border:1px solid rgba(255,255,255,.45);border-radius:999px;padding:1px 8px}
.wrb-flag{position:absolute;right:10px;top:10px;font-size:11px;font-weight:700;color:var(--brand);background:#fff;padding:2px 8px;border-radius:999px}
.wrb-flag.is-arch{background:#6b6b6b;color:#fff}
.wrb-cap{position:absolute;left:0;right:0;bottom:0;padding:16px;color:#fff}
.wrb-nm{display:block;font-weight:700;line-height:1.25;letter-spacing:.02em;font-size:24px;word-break:keep-all;overflow-wrap:anywhere}
.wrb-nm.is-m{font-size:20px}.wrb-nm.is-l{font-size:17px}
.wrb-by{display:block;font-size:12px;color:var(--cream);margin-top:4px}
.wrb-body{display:flex;flex-direction:column;gap:6px;padding:14px 14px 12px;flex:1}
.wrb-meta{font-size:12px;color:var(--brand);font-weight:700;line-height:1.5}
.wrb-keys{display:flex;flex-wrap:wrap;gap:2px 10px;font-size:13px;font-variant-numeric:tabular-nums}
.wrb-keys span+span::before{content:"·";margin-right:10px;color:var(--rose)}
.wrb-cask{font-size:12px;color:var(--sub);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.wrb-price{display:flex;flex-direction:column;gap:0;margin-top:4px;line-height:1.3}
.wrb-price b{font-size:22px;font-variant-numeric:tabular-nums;white-space:nowrap}
.wrb-price small{font-size:12px;color:var(--sub);white-space:nowrap}
.wrb-price.is-na b{font-size:14px;font-weight:400;color:var(--sub);padding:5px 0}
.wrb-judge{margin-top:auto;padding-top:10px;border-top:1px dashed var(--line);display:grid;gap:5px;font-size:12px}
.wrb-judge div{display:block;line-height:1.5}
.wrb-chip{display:inline-flex;align-items:center;gap:6px;font-weight:700}
.wrb-dot{flex:none;display:inline-block;width:9px;height:9px;border-radius:50%;background:#bbb}
.wrb-dot.jp_official{background:var(--brand)}.wrb-dot.jp_route{background:var(--gold)}.wrb-dot.jp_parallel{background:#c46a1c}
.wrb-dot.buy_online{background:#2f6f86}
.wrb-judge em{display:block;font-style:normal;color:var(--sub);padding-left:15px;font-size:11px}
.wrb-go{font-size:12px;color:var(--brand);font-weight:700;text-align:right;margin-top:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wrb-sec{margin:36px 0 0}
#wrb h2.wrb-h2,#wrb h3.wrb-nm,#wrb h3.wrb-q{all:unset;box-sizing:border-box;background:none!important;border:0!important;border-radius:0!important;box-shadow:none!important;padding:0!important;margin:0!important;position:static!important;text-align:left!important}
#wrb h2.wrb-h2::before,#wrb h2.wrb-h2::after,#wrb h3.wrb-nm::before,#wrb h3.wrb-nm::after,#wrb h3.wrb-q::before,#wrb h3.wrb-q::after{content:none!important;display:none!important}
#wrb h2.wrb-h2{display:flex!important;align-items:center;gap:10px;font-size:19px!important;font-weight:700!important;color:var(--ink)!important;line-height:1.4!important;margin:0 0 12px!important;padding:0 0 8px!important;border-bottom:2px solid var(--brand)!important}
#wrb h2.wrb-h2 small{font-size:12px;font-weight:400;color:var(--sub)}
.wrb-sec-lead{font-size:13px!important;color:var(--sub);margin:0 0 12px!important;line-height:1.7!important}
.wrb-bl{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:6px;margin:0!important;padding:0!important;list-style:none!important}
.wrb-bl li{margin:0!important;padding:0!important;border:0!important;background:none!important}
.wrb-bl li::before{display:none!important}
.wrb-bl button,.wrb-bl span.off{width:100%;display:flex;justify-content:space-between;align-items:baseline;gap:8px;text-align:left;border:1px solid var(--line);background:var(--card);border-radius:8px;padding:8px 10px;font-size:13px;color:var(--ink);line-height:1.4;cursor:pointer}
.wrb-bl button:hover{border-color:var(--brand)}
.wrb-bl span.off{cursor:default;color:var(--sub);background:var(--soft)}
.wrb-bl i{font-style:normal;font-size:11px;color:var(--sub);display:block}
.wrb-bl b{font-weight:700;font-variant-numeric:tabular-nums;color:var(--brand);white-space:nowrap}
.wrb-bl span.off b{color:var(--sub);font-weight:400;font-size:11px}
.wrb-faq{display:grid;gap:8px}
.wrb-faq details{border:1px solid var(--line);border-radius:10px;background:var(--card)}
.wrb-faq summary{list-style:none;cursor:pointer;padding:12px 14px;display:flex;justify-content:space-between;gap:10px}
.wrb-faq summary::-webkit-details-marker{display:none}
.wrb-faq summary::after{content:"＋";color:var(--brand);flex:none}
.wrb-faq details[open] summary::after{content:"－"}
#wrb h3.wrb-nm{display:block!important;font-weight:700!important;line-height:1.25!important;letter-spacing:.02em;font-size:24px!important;color:#fff!important;word-break:keep-all;overflow-wrap:anywhere}
#wrb h3.wrb-nm.is-m{font-size:20px!important}#wrb h3.wrb-nm.is-l{font-size:17px!important}
#wrb h3.wrb-q{display:block!important;font-size:14px!important;font-weight:700!important;color:var(--ink)!important;line-height:1.5!important}
#wrb .wrb-faq .a{padding:0 14px 14px;font-size:14px!important;line-height:1.8!important;color:var(--ink);margin:0!important}
.wrb-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.wrb-empty{padding:40px;text-align:center;color:var(--sub)}
.wrb-archbtn{display:block;margin:18px auto 0;border:1px solid var(--line);background:var(--card);color:var(--ink);border-radius:999px;padding:9px 18px;font-size:13px;cursor:pointer}
.wrb-archbtn[aria-pressed="true"]{background:var(--soft)}
.wrb-tabs .lbl+.lbl,.wrb-tabs .wrb-tab+.lbl{margin-top:6px}
.wrb-small{font-size:12px;color:var(--sub);margin-top:24px;padding-top:14px;border-top:1px solid var(--line);display:grid;gap:6px}
@media (max-width:900px){.wrb-list{grid-template-columns:repeat(2,1fr)}.wrb-filters{grid-template-columns:1fr 1fr}}
@media (max-width:600px){
.wrb-list{grid-template-columns:1fr}.wrb-media{aspect-ratio:2/1}
.wrb-hero .u{margin-left:0;width:100%}.wrb-hero .v{font-size:22px}
.wrb-more{display:flex;width:100%;justify-content:center;gap:6px;border:1px solid var(--line);background:var(--card);border-radius:8px;height:42px;align-items:center;font-size:14px;color:var(--ink);cursor:pointer}
.wrb-adv{display:none;margin-top:8px}.wrb-adv.is-open{display:block}
.wrb-filters{grid-template-columns:1fr 1fr}.wrb-filters .wrb-s{grid-column:1/-1}
}
</style>

<div class="wrb-intro">
	<p>「気になっていたボトラーの新作、いつの間にか出ていた」。そんな見逃しを少しでも減らしたくて、このページを作りました。</p>
	<p>著名なボトラー<?php echo $n_all; ?>社のリリースについて国内外の情報を取りまとめ、日本で買えるか、海外通販で買えるかまで、できる限り調べて載せています。国内入荷を待つか、海外から取り寄せるか。迷ったときの判断材料にしてもらえたらうれしいです！</p>
	<ul class="wrb-notes">
		<li>掲載はボトラーの公式発表をもとにしています。特定の輸入元や販売店に偏らない、中立な一覧を目指しています。</li>
		<li>掲載するボトラーや情報の集め方は、今後も改善していく予定です。ご意見は<a href="https://www.instagram.com/whisky_roundup/" target="_blank" rel="noopener">InstagramのDM</a>からお気軽にどうぞ！</li>
	</ul>
</div>

<div class="wrb-hero">
	<div><div class="k">掲載中のリリース</div><div class="v"><?php echo count( $active ); ?><small>件</small></div></div>
	<div><div class="k">新作のあったボトラー</div><div class="v"><?php echo $n_with; ?><small>社（追跡中 <?php echo $n_all; ?>社）</small></div></div>
	<div class="u">最終更新 <?php echo esc_html( wr_bottlers_fmt_date( $data['updated'] ) ); ?>・毎週月曜更新</div>
</div>

<details class="wrb-help">
	<summary>このページの見方</summary>
	<div class="in">
		<div><span class="h">日本での入手</span><dl>
		<?php foreach ( $jp_order as $k ) : ?><dt><span class="wrb-dot <?php echo $k; ?>"></span><?php echo esc_html( $L['jp_status'][ $k ] ); ?></dt><dd><?php echo esc_html( $L['jp_help'][ $k ] ); ?></dd><?php endforeach; ?>
		</dl></div>
		<div><span class="h">海外通販</span><dl>
		<?php foreach ( array( 'buy_online', 'buy_unknown' ) as $k ) : ?><dt><span class="wrb-dot <?php echo $k; ?>"></span><?php echo esc_html( $L['buy_status'][ $k ] ); ?></dt><dd><?php echo esc_html( $L['buy_help'][ $k ] ); ?></dd><?php endforeach; ?>
		</dl></div>
		<div><span class="h">カードの色＝地域</span><?php foreach ( $legend_rg as $rg ) : ?><span class="wrb-lg" style="--tc:<?php echo esc_attr( wr_bottlers_region_color( $rg ) ); ?>"><?php echo esc_html( $rg ); ?></span><?php endforeach; ?><span class="wrb-lg" style="--tc:#630000">その他</span></div>
		<div><span class="h">価格</span><p>ボトラーの公式価格です。円は<?php echo esc_html( wr_bottlers_fmt_date( isset( $fx['date'] ) ? $fx['date'] : '' ) ); ?>時点のレートで換算した目安で、送料・関税・酒税・消費税は含みません。</p></div>
	</div>
</details>

<div class="wrb-ctrl">
	<div class="wrb-tabs" role="group" aria-label="日本での入手で絞り込む">
		<span class="lbl">日本での入手で絞り込む</span>
		<button type="button" class="wrb-tab" data-jp="all" aria-pressed="true">すべて<span class="c"><?php echo count( $active ); ?></span></button>
		<?php foreach ( $jp_order as $k ) : if ( empty( $jp_count[ $k ] ) ) continue; ?>
		<button type="button" class="wrb-tab" data-jp="<?php echo $k; ?>" aria-pressed="false"><span class="wrb-dot <?php echo $k; ?>"></span><?php echo esc_html( $L['jp_status'][ $k ] ); ?><span class="c"><?php echo $jp_count[ $k ]; ?></span></button>
		<?php endforeach; ?>
		<span class="lbl">海外通販</span>
		<button type="button" class="wrb-tab wrb-buy" aria-pressed="false"><span class="wrb-dot buy_online"></span>海外通販ありだけ表示<span class="c"><?php echo count( array_filter( $active, function ( $x ) { return $x['buy_status'] === 'buy_online'; } ) ); ?></span></button>
	</div>
	<div class="wrb-qw"><input type="search" class="wrb-q" placeholder="蒸留所・ボトラー名で検索（英語も可）" aria-label="キーワード検索"></div>
	<button type="button" class="wrb-more" aria-expanded="false">絞り込み・並び替え <span aria-hidden="true">▾</span></button>
	<div class="wrb-adv">
		<div class="wrb-filters">
			<select class="wrb-b" aria-label="ボトラーで絞り込む"><option value="">すべてのボトラー</option>
			<?php foreach ( $bottlers as $b ) : ?><option><?php echo esc_html( $b ); ?></option><?php endforeach; ?></select>
			<select class="wrb-r" aria-label="地域で絞り込む"><option value="">すべての地域</option>
			<?php foreach ( $regions as $r ) : ?><option><?php echo esc_html( $r ); ?></option><?php endforeach; ?></select>
			<select class="wrb-s" aria-label="並び替え"><option value="new">新しい順</option><option value="cheap">価格の安い順</option><option value="high">価格の高い順</option></select>
		</div>
</div>

<h2 class="wrb-h2 st-css-no st-css-no2" id="wrb-list">最新リリース一覧</h2>
<div class="wrb-result" aria-live="polite"><span><b class="wrb-n"><?php echo count( $active ); ?></b> 件を表示中</span><button type="button" class="wrb-reset" hidden>条件をリセット</button></div>

<div class="wrb-list">
<?php $i = 0; foreach ( $items as $x ) :
	if ( ! empty( $x['name'] ) ) {
		$title = $x['name'];
	} else {
		$title = trim( preg_replace( '/[（(].*?[）)]/u', '', $x['distillery'] ) );
		if ( $x['age'] ) $title .= ' ' . $x['age'] . '年';
		if ( $x['vintage'] ) $title .= ' ' . $x['vintage'];
	}
	$tlen  = mb_strlen( $title );
	$tsize = $tlen > 18 ? ' is-l' : ( $tlen > 12 ? ' is-m' : '' );
	$keys  = array();
	if ( $x['abv'] ) $keys[] = rtrim( rtrim( number_format( $x['abv'], 1 ), '0' ), '.' ) . '%';
	if ( $x['bottles'] ) $keys[] = number_format( $x['bottles'] ) . '本限定';
	if ( $x['size_ml'] ) $keys[] = $x['size_ml'] . 'ml';
	if ( ! empty( $x['collab'] ) ) $keys[] = '提携：' . $x['collab'];
	$detail = trim( ( $x['cask'] ? '樽：' . $x['cask'] . '。' : '' ) . $x['note'] );
	$date   = $x['announced'] ? wr_bottlers_fmt_date( $x['announced'] ) . ' 発表' : wr_bottlers_fmt_date( $x['first_seen'] ) . ' 掲載確認';
	$search = mb_strtolower( implode( ' ', array( $title, isset( $x['name_en'] ) ? $x['name_en'] : '', $x['_bottler'], $x['series'], $x['distillery'], $x['region'], $x['cask'], $x['note'] ) ) );
	$price  = wr_bottlers_price( $x['price'], $fx );
	$rg     = $x['region'] ? $x['region'] : 'その他';
	?>
	<a class="wrb-card<?php echo $x['_archived'] ? ' is-arch' : ''; ?>" href="<?php echo esc_url( $x['source_url'] ); ?>" target="_blank" rel="noopener nofollow" data-i="<?php echo $i++; ?>" data-p="<?php echo $price && $price[2] ? round( $price[2], 2 ) : ''; ?>" data-jp="<?php echo esc_attr( $x['jp_status'] ); ?>" data-buy="<?php echo esc_attr( $x['buy_status'] ); ?>" data-b="<?php echo esc_attr( $x['_bottler'] ); ?>" data-r="<?php echo esc_attr( $x['region'] ); ?>" data-arch="<?php echo $x['_archived'] ? '1' : '0'; ?>" data-s="<?php echo esc_attr( $search ); ?>"<?php echo $x['_archived'] ? ' hidden' : ''; ?>>
		<div class="wrb-media" style="--tc:<?php echo esc_attr( wr_bottlers_region_color( $x['region'] ) ); ?>">
			<span class="wrb-rg"><?php echo esc_html( $rg ); ?></span>
			<?php if ( $x['_archived'] ) : ?><span class="wrb-flag is-arch">90日以上前</span><?php elseif ( $x['_new'] ) : ?><span class="wrb-flag">NEW</span><?php endif; ?>
			<div class="wrb-cap"><h3 class="wrb-nm st-css-no st-css-no2<?php echo $tsize; ?>"><?php echo esc_html( $title ); ?></h3><span class="wrb-by"><?php echo esc_html( $x['_bottler'] ); ?></span></div>
		</div>
		<div class="wrb-body">
			<?php if ( $x['series'] ) : ?><div class="wrb-meta"><?php echo esc_html( $x['series'] ); ?></div><?php endif; ?>
			<?php if ( $keys ) : ?><div class="wrb-keys"><?php foreach ( $keys as $k ) : ?><span><?php echo esc_html( $k ); ?></span><?php endforeach; ?></div><?php endif; ?>
			<?php if ( $detail ) : ?><div class="wrb-cask"><?php echo esc_html( $detail ); ?></div><?php endif; ?>
			<?php if ( $price ) : ?><div class="wrb-price"><b><?php echo esc_html( $price[0] ); ?></b><small><?php echo $price[1] ? esc_html( $price[1] ) . '・税送料別' : '円換算なし'; ?></small></div>
			<?php else : ?><div class="wrb-price is-na"><b>公式価格は未発表</b></div><?php endif; ?>
			<?php if ( ! empty( $x['jp_price'] ) ) : ?><div class="wrb-cask">国内公式価格 ¥<?php echo number_format( $x['jp_price'] ); ?>（税込）</div><?php endif; ?>
			<div class="wrb-judge">
				<div><span class="wrb-chip" title="<?php echo esc_attr( $L['jp_help'][ $x['jp_status'] ] ); ?>"><span class="wrb-dot <?php echo esc_attr( $x['jp_status'] ); ?>"></span><?php echo esc_html( $L['jp_status'][ $x['jp_status'] ] ); ?></span><?php if ( $x['jp_note'] ) : ?><em><?php echo esc_html( $x['jp_note'] ); ?></em><?php endif; ?></div>
				<div><span class="wrb-chip" title="<?php echo esc_attr( $L['buy_help'][ $x['buy_status'] ] ); ?>"><span class="wrb-dot <?php echo esc_attr( $x['buy_status'] ); ?>"></span><?php echo esc_html( $L['buy_status'][ $x['buy_status'] ] ); ?></span><?php if ( $x['buy_note'] ) : ?><em><?php echo esc_html( $x['buy_note'] ); ?></em><?php endif; ?></div>
			</div>
			<div class="wrb-go">元記事を開く（<?php echo esc_html( $x['source_name'] ); ?>）↗<span class="wrb-sr">新しいタブで開きます</span></div>
		</div>
	</a>
<?php endforeach; ?>
</div>
<p class="wrb-empty" hidden>条件に合うリリースはありません。条件を変えるか、リセットしてください。</p>
<?php if ( $archived ) : ?><button type="button" class="wrb-archbtn" aria-pressed="false">90日以上前のリリースも見る（<?php echo $archived; ?>件）</button><?php endif; ?>

<section class="wrb-sec" aria-labelledby="wrb-bl-h">
	<h2 class="wrb-h2 st-css-no st-css-no2" id="wrb-bl-h">掲載しているボトラー<small><?php echo $n_all; ?>社</small></h2>
	<p class="wrb-sec-lead">以下のボトラーの公式発表を毎週チェックしています。名前を押すと、そのボトラーのリリースだけを表示します。「確認中」は、公式サイトの情報を自動で取得できず、新作の有無をまだ確かめられていないボトラーです。</p>
	<ul class="wrb-bl">
	<?php
	$bcount = array_count_values( array_column( $active, '_bottler' ) );
	$blist  = $data['bottlers'];
	usort( $blist, function ( $a, $b ) use ( $bcount ) {
		$ca = isset( $bcount[ $a['name'] ] ) ? $bcount[ $a['name'] ] : 0;
		$cb = isset( $bcount[ $b['name'] ] ) ? $bcount[ $b['name'] ] : 0;
		return $cb - $ca;
	} );
	foreach ( $blist as $bt ) :
		$c  = isset( $bcount[ $bt['name'] ] ) ? $bcount[ $bt['name'] ] : 0;
		$en = isset( $bt['name_en'] ) ? $bt['name_en'] : '';
		?>
		<li><?php if ( $c ) : ?><button type="button" data-bt="<?php echo esc_attr( $bt['name'] ); ?>"><span><?php echo esc_html( $bt['name'] ); ?><i><?php echo esc_html( $en ); ?></i></span><b><?php echo $c; ?>件</b></button><?php else : ?><span class="off"><span><?php echo esc_html( $bt['name'] ); ?><i><?php echo esc_html( $en ); ?></i></span><b><?php echo empty( $bt['tier_confirmed'] ) ? '確認中' : '直近の新作なし'; ?></b></span><?php endif; ?></li>
	<?php endforeach; ?>
	</ul>
</section>

<section class="wrb-sec" aria-labelledby="wrb-faq-h">
	<h2 class="wrb-h2 st-css-no st-css-no2" id="wrb-faq-h">よくある質問</h2>
	<div class="wrb-faq">
	<?php
	$faq = array(
		array( 'ボトラーズ（インディペンデントボトラー）とは？', '蒸留所から樽ごと原酒を買い付け、独自に熟成・瓶詰めして販売する会社のことです。同じ蒸留所でも、オフィシャルとはひと味違う個性や、1樽だけの限定品に出会えるのが魅力です。' ),
		array( '「' . $L['jp_status']['jp_route'] . '」とはどういう意味ですか？', 'そのボトラーの商品を扱う日本の正規輸入元はあるものの、この商品の日本への入荷はまだ発表されていない状態です。後日、国内で発売される可能性があります。' ),
		array( '海外通販で買うときの注意点は？', '個人で飲む分の酒類は海外から取り寄せられますが、商品代とは別に送料・関税・酒税・消費税がかかります。日本へ発送しているかはお店ごとに違うので、購入前に各店の配送ポリシーを確認してください。20歳未満の方は購入できません。' ),
		array( '情報はどこから集めていますか？', '各ボトラーの公式サイトやニュース、公式発表を伝える業界メディアです。特定の輸入元や販売店の情報に偏らないよう、ボトラーの公式発表を起点にしています。' ),
		array( 'どのくらいの頻度で更新されますか？', '毎週月曜日に更新しています。発表から' . $days . '日を過ぎたリリースは、一覧の下の「' . $days . '日以上前のリリース」にまとめています。' ),
	);
	foreach ( $faq as $f ) : ?>
		<details><summary><h3 class="wrb-q st-css-no st-css-no2"><?php echo esc_html( $f[0] ); ?></h3></summary><p class="a"><?php echo esc_html( $f[1] ); ?></p></details>
	<?php endforeach; ?>
	</div>
</section>

<?php
// --- 2. 構造化データ（一覧ページとして登録。商品としては登録しない）
$ld_items = array();
$pos = 1;
foreach ( $active as $x ) {
	$nm = ! empty( $x['name'] ) ? $x['name'] : $x['distillery'];
	$ld_items[] = array( '@type' => 'ListItem', 'position' => $pos++, 'name' => $nm . '（' . $x['_bottler'] . '）', 'url' => $x['source_url'] );
}
$ld = array(
	'@context'     => 'https://schema.org',
	'@type'        => 'CollectionPage',
	'name'         => get_the_title(),
	'description'  => '世界のインディペンデントボトラー' . $n_all . '社の新作情報を毎週更新。日本で買えるか、海外通販で買えるか、公式価格まで一覧で確認できます。',
	'url'          => get_permalink(),
	'inLanguage'   => 'ja',
	'dateModified' => $data['updated'],
	'publisher'    => array( '@type' => 'Organization', 'name' => 'Whisky Roundup', 'url' => home_url( '/' ) ),
	'mainEntity'   => array( '@type' => 'ItemList', 'numberOfItems' => count( $ld_items ), 'itemListElement' => $ld_items ),
);
$faq_ld = array();
?>
<script type="application/ld+json"><?php echo wp_json_encode( $ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>

<div class="wrb-small">
	<p>世界のインディペンデントボトラーが公式に発表したリリースを、輸入元や販売店に偏らず掲載しています。蒸留所のオフィシャル、酒販店・バー・イベント向けの限定ボトルは掲載していません。カードを押すと元記事（外部サイト）が新しいタブで開きます。</p>
	<?php if ( $tier_b ) : ?><p>次のボトラーは公式発表が限られるため、一部のリリースのみ掲載しています：<?php echo esc_html( implode( '、', $tier_b ) ); ?></p><?php endif; ?>
	<p>20歳未満の飲酒は法律で禁止されています。</p>
</div>

<script>
(function(){
	var root=document.getElementById('wrb');if(!root)return;
	var list=root.querySelector('.wrb-list'),tabs=root.querySelectorAll('.wrb-tab[data-jp]'),cards=[].slice.call(root.querySelectorAll('.wrb-card'));
	var empty=root.querySelector('.wrb-empty'),num=root.querySelector('.wrb-n'),reset=root.querySelector('.wrb-reset');
	var b=root.querySelector('.wrb-b'),r=root.querySelector('.wrb-r'),q=root.querySelector('.wrb-q'),s=root.querySelector('.wrb-s'),buyBtn=root.querySelector('.wrb-buy'),archBtn=root.querySelector('.wrb-archbtn');
	var buy=false,arch=false;
	var more=root.querySelector('.wrb-more'),adv=root.querySelector('.wrb-adv');
	var jp='all';
	more.addEventListener('click',function(){var o=adv.classList.toggle('is-open');more.setAttribute('aria-expanded',o?'true':'false');});
	function sortCards(){
		var m=s.value,c=cards.slice();
		c.sort(function(x,y){
			if(m==='new')return x.dataset.i-y.dataset.i;
			var px=x.dataset.p===''?null:+x.dataset.p,py=y.dataset.p===''?null:+y.dataset.p;
			if(px===null&&py===null)return x.dataset.i-y.dataset.i;if(px===null)return 1;if(py===null)return -1;
			return m==='cheap'?px-py:py-px;
		});
		c.forEach(function(el){list.appendChild(el);});
	}
	function apply(){
		var k=q.value.trim().toLowerCase(),n=0;
		cards.forEach(function(c){
			var d=c.dataset,ok=(arch||d.arch==='0')&&(jp==='all'||d.jp===jp)&&(!b.value||d.b===b.value)&&(!r.value||d.r===r.value)&&(!buy||d.buy==='buy_online')&&(!k||d.s.indexOf(k)>-1);
			c.hidden=!ok;if(ok)n++;
		});
		num.textContent=n;empty.hidden=n>0;
		reset.hidden=!(jp!=='all'||b.value||r.value||k||buy||arch||s.value!=='new');
	}
	tabs.forEach(function(t){t.addEventListener('click',function(){jp=t.dataset.jp;tabs.forEach(function(x){x.setAttribute('aria-pressed',x===t?'true':'false');});apply();});});
	[b,r].forEach(function(el){el.addEventListener('change',apply);});
	buyBtn.addEventListener('click',function(){buy=!buy;buyBtn.setAttribute('aria-pressed',buy?'true':'false');apply();});
	if(archBtn)archBtn.addEventListener('click',function(){arch=!arch;archBtn.setAttribute('aria-pressed',arch?'true':'false');archBtn.textContent=arch?'90日以上前のリリースを隠す':archBtn.dataset.label;apply();});
	if(archBtn)archBtn.dataset.label=archBtn.textContent;
	s.addEventListener('change',function(){sortCards();apply();});
	q.addEventListener('input',apply);
	root.querySelectorAll('.wrb-bl button').forEach(function(btn){btn.addEventListener('click',function(){
		b.value=btn.dataset.bt;adv.classList.add('is-open');more.setAttribute('aria-expanded','true');apply();
		var t=root.querySelector('#wrb-list');if(t)t.scrollIntoView({behavior:'smooth',block:'start'});
	});});
	reset.addEventListener('click',function(){jp='all';tabs.forEach(function(x){x.setAttribute('aria-pressed',x.dataset.jp==='all'?'true':'false');});b.value='';r.value='';q.value='';s.value='new';buy=false;buyBtn.setAttribute('aria-pressed','false');if(arch&&archBtn){archBtn.click();}arch=false;sortCards();apply();});
})();
</script>
</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'bottlers_list', 'wr_bottlers_shortcode' );
