<?php
/**
 * Whisky Roundup ボトラーズ最新リリース（世界版）v1.3.0
 * 使い方：プラグインとして有効化（またはCode Snippetsに「PHP」タイプで貼り付け）→ 固定ページに [bottlers_list]
 * データ：GitHub の data/releases.json（毎週自動更新）を1時間キャッシュ
 * 要件：REQUIREMENTS.md
 */

if ( ! defined( 'WR_BOTTLERS_JSON' ) ) {
	define( 'WR_BOTTLERS_JSON', 'https://raw.githubusercontent.com/whiskyroundup-beep/bottlers-releases/main/data/releases.json' );
}

function wr_bottlers_get_data() {
	$cache = get_transient( 'wr_bottlers_data_v3' );
	if ( $cache !== false ) {
		return $cache;
	}
	$res  = wp_remote_get( WR_BOTTLERS_JSON, array( 'timeout' => 10 ) );
	$data = null;
	if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
	}
	if ( ! is_array( $data ) || ! isset( $data['releases'] ) ) {
		$backup = get_option( 'wr_bottlers_backup_v3' );
		return $backup ? $backup : null;
	}
	set_transient( 'wr_bottlers_data_v3', $data, HOUR_IN_SECONDS );
	update_option( 'wr_bottlers_backup_v3', $data, false );
	return $data;
}

function wr_bottlers_fmt_date( $d ) {
	if ( ! $d ) return '';
	$p = explode( '-', $d );
	if ( count( $p ) === 3 ) return intval( $p[0] ) . '.' . intval( $p[1] ) . '.' . intval( $p[2] );
	if ( count( $p ) === 2 ) return intval( $p[0] ) . '.' . intval( $p[1] );
	return $d;
}

function wr_bottlers_price( $price, $fx ) {
	if ( empty( $price ) || empty( $price['amount'] ) ) return null;
	$sym = array( 'GBP' => '£', 'USD' => '$', 'EUR' => '€', 'JPY' => '¥' );
	$c   = $price['currency'];
	$a   = $price['amount'];
	$main = ( isset( $sym[ $c ] ) ? $sym[ $c ] : $c . ' ' ) . number_format( $a, ( floor( $a ) == $a ) ? 0 : 2 );
	$yen  = ( $c !== 'JPY' && ! empty( $fx[ $c ] ) ) ? '約' . number_format( round( $a * $fx[ $c ], -2 ) ) . '円' : '';
	return array( $main, $yen );
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
		$x['_base']     = $base;
		$x['_archived'] = ( ( strtotime( $today ) - strtotime( $base ) ) / 86400 > $days ) && empty( $x['jp_pending'] );
		$x['_bottler']  = isset( $bmap[ $x['bottler_id'] ] ) ? $bmap[ $x['bottler_id'] ]['name'] : $x['bottler_id'];
		$items[]        = $x;
	}
	usort( $items, function ( $a, $b ) { return strcmp( $b['_base'], $a['_base'] ); } );

	$active   = array_filter( $items, function ( $x ) { return ! $x['_archived']; } );
	$archived = count( $items ) - count( $active );
	$jp_order = array( 'jp_official', 'jp_route', 'jp_parallel', 'jp_unknown', 'jp_none' );
	$jp_count = array_count_values( array_column( $active, 'jp_status' ) );
	$bottlers = array_unique( array_column( $items, '_bottler' ) );
	sort( $bottlers );
	$regions = array_unique( array_filter( array_column( $items, 'region' ) ) );
	sort( $regions );
	$tier_b = array();
	foreach ( $data['bottlers'] as $b ) if ( $b['tier'] === 'B' ) $tier_b[] = $b['name'];
	$n_bottlers = count( array_unique( array_column( $active, 'bottler_id' ) ) );

	ob_start();
	?>
<div class="wrb" id="wrb">
<style>
.wrb{--ink:#231710;--sub:#76655a;--line:#e8dfcf;--soft:#f7f3ea;--card:#fff;--brand:#630000;--cream:#eeebdd;--rose:#d8b6a4;--gold:#b8860b;color:var(--ink);font-size:15px;line-height:1.6}
.wrb *{box-sizing:border-box}.wrb [hidden]{display:none!important}
.wrb a,.wrb a:hover{text-decoration:none;color:inherit;box-shadow:none}
.wrb-hero{background:var(--brand);color:#fff;border-radius:14px;padding:18px 20px;margin:0 0 16px;display:flex;flex-wrap:wrap;gap:12px 28px;align-items:flex-end}
.wrb-hero .k{font-size:11px;letter-spacing:.12em;color:var(--rose)}
.wrb-hero .v{font-size:26px;font-weight:700;line-height:1.1;font-variant-numeric:tabular-nums}
.wrb-hero .v small{font-size:13px;font-weight:400;margin-left:2px}
.wrb-hero .u{margin-left:auto;font-size:12px;color:var(--cream);opacity:.85}
.wrb-bar{position:sticky;top:0;z-index:5;background:rgba(255,255,255,.96);backdrop-filter:blur(6px);padding:10px 0 12px;margin:0 0 14px;border-bottom:1px solid var(--line)}
.wrb-tabs{display:flex;gap:6px;overflow-x:auto;scrollbar-width:none;margin:0 0 10px;padding-bottom:2px}
.wrb-tabs::-webkit-scrollbar{display:none}
.wrb-tab{flex:none;border:1px solid var(--line);background:var(--card);color:var(--ink);border-radius:999px;padding:7px 14px;font-size:13px;cursor:pointer;line-height:1.2}
.wrb-tab span{color:var(--sub);margin-left:5px;font-variant-numeric:tabular-nums}
.wrb-tab[aria-pressed="true"]{background:var(--ink);border-color:var(--ink);color:#fff}
.wrb-tab[aria-pressed="true"] span{color:var(--rose)}
.wrb-filters{display:grid;grid-template-columns:1fr 1fr 1.6fr;gap:8px}
.wrb-filters select,.wrb-filters input[type=search]{width:100%;height:40px;border:1px solid var(--line);border-radius:8px;padding:0 10px;font-size:14px;background:var(--card);color:var(--ink);margin:0}
.wrb-checks{display:flex;flex-wrap:wrap;gap:6px 18px;margin-top:8px;font-size:13px;color:var(--sub)}
.wrb-checks label{display:flex;gap:6px;align-items:center;cursor:pointer;margin:0}
.wrb-list{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.wrb-card{display:flex;flex-direction:column;background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden;transition:transform .15s,box-shadow .15s}
.wrb-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(35,23,16,.10)}
.wrb-card.is-arch{opacity:.75}
.wrb-media{position:relative;aspect-ratio:16/10;background:var(--soft);overflow:hidden}
.wrb-tile{position:absolute;inset:0;background:linear-gradient(160deg,var(--tc) 0%,#1a110b 100%)}
.wrb-tile::before{content:"";position:absolute;right:-30px;top:-30px;width:140px;height:140px;border-radius:50%;border:1px solid rgba(255,255,255,.18)}
.wrb-cap{position:absolute;left:0;right:0;bottom:0;padding:16px;color:#fff}
.wrb-cap .wrb-nm{display:block;font-weight:700;line-height:1.25;letter-spacing:.02em;font-size:24px;word-break:keep-all;overflow-wrap:anywhere;text-shadow:0 1px 2px rgba(0,0,0,.35)}
.wrb-cap .wrb-nm.is-m{font-size:20px}.wrb-cap .wrb-nm.is-l{font-size:17px}
.wrb-cap .wrb-by{display:block;font-size:12px;opacity:.85;margin-top:4px}
.wrb-flag{position:absolute;right:8px;top:8px;font-size:11px;font-weight:700;color:#fff;background:var(--brand);padding:2px 8px;border-radius:999px}
.wrb-flag.is-arch{background:#6b6b6b}
.wrb-body{display:flex;flex-direction:column;gap:6px;padding:14px 14px 12px;flex:1}
.wrb-meta{display:flex;justify-content:space-between;gap:8px;font-size:12px;color:var(--sub);letter-spacing:.02em}.wrb-meta span:first-child{color:var(--brand);font-weight:700}.wrb-meta span:last-child{white-space:nowrap}
.wrb-meta b{color:var(--brand);font-weight:700}
.wrb-series{font-size:13px;color:var(--ink);font-weight:700}
.wrb-keys{display:flex;flex-wrap:wrap;gap:4px 10px;font-size:13px;font-variant-numeric:tabular-nums;color:var(--ink)}
.wrb-keys span+span::before{content:"·";margin-right:10px;color:var(--rose)}
.wrb-cask{font-size:12px;color:var(--sub);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.wrb-price{display:flex;align-items:baseline;gap:8px;margin-top:2px}
.wrb-price b{font-size:20px;font-variant-numeric:tabular-nums}
.wrb-price small{font-size:12px;color:var(--sub)}
.wrb-price.is-na b{font-size:13px;font-weight:400;color:var(--sub)}
.wrb-judge{margin-top:auto;padding-top:10px;border-top:1px dashed var(--line);display:grid;gap:4px;font-size:12px}
.wrb-judge div{display:flex;gap:7px;align-items:baseline;line-height:1.45}
.wrb-dot{flex:none;width:8px;height:8px;border-radius:50%;background:#bbb;transform:translateY(-1px)}
.wrb-dot.jp_official{background:var(--brand)}.wrb-dot.jp_route{background:var(--gold)}.wrb-dot.jp_parallel{background:#c46a1c}
.wrb-dot.buy_online{background:#2f6f86}
.wrb-judge em{font-style:normal;color:var(--sub);margin-left:4px}
.wrb-go{font-size:12px;color:var(--brand);font-weight:700;text-align:right;margin-top:6px}
.wrb-empty{padding:40px;text-align:center;color:var(--sub)}
.wrb-small{font-size:12px;color:var(--sub);margin-top:24px;padding-top:14px;border-top:1px solid var(--line)}
.wrb-small p{margin:0 0 6px}
.wrb-lg{display:inline-flex;align-items:center;gap:4px;margin:0 10px 0 4px;white-space:nowrap}.wrb-lg::before{content:"";width:10px;height:10px;border-radius:3px;background:var(--tc)}
@media (max-width:900px){.wrb-list{grid-template-columns:repeat(2,1fr)}}
@media (max-width:600px){.wrb-list{grid-template-columns:1fr}.wrb-media{aspect-ratio:2/1}.wrb-filters{grid-template-columns:1fr 1fr}.wrb-filters .wrb-q{grid-column:1/-1}.wrb-hero .u{margin-left:0;width:100%}.wrb-hero .v{font-size:22px}}
</style>

<div class="wrb-hero">
	<div><div class="k">RELEASES</div><div class="v"><?php echo count( $active ); ?><small>件</small></div></div>
	<div><div class="k">BOTTLERS</div><div class="v"><?php echo $n_bottlers; ?><small>社</small></div></div>
	<div class="u">最終更新 <?php echo esc_html( wr_bottlers_fmt_date( $data['updated'] ) ); ?>・毎週月曜更新</div>
</div>

<div class="wrb-bar">
	<div class="wrb-tabs" role="group" aria-label="国内導入で絞り込む">
		<button type="button" class="wrb-tab" data-jp="all" aria-pressed="true">すべて<span><?php echo count( $active ); ?></span></button>
		<?php foreach ( $jp_order as $k ) : if ( empty( $jp_count[ $k ] ) ) continue; ?>
		<button type="button" class="wrb-tab" data-jp="<?php echo $k; ?>" aria-pressed="false"><?php echo esc_html( $L['jp_status'][ $k ] ); ?><span><?php echo $jp_count[ $k ]; ?></span></button>
		<?php endforeach; ?>
	</div>
	<div class="wrb-filters">
		<select class="wrb-b" aria-label="ボトラーで絞り込む"><option value="">すべてのボトラー</option>
		<?php foreach ( $bottlers as $b ) : ?><option><?php echo esc_html( $b ); ?></option><?php endforeach; ?></select>
		<select class="wrb-r" aria-label="地域で絞り込む"><option value="">すべての地域</option>
		<?php foreach ( $regions as $r ) : ?><option><?php echo esc_html( $r ); ?></option><?php endforeach; ?></select>
		<input type="search" class="wrb-q" placeholder="蒸留所名・キーワードで検索" aria-label="キーワード検索">
	</div>
	<div class="wrb-checks">
		<label><input type="checkbox" class="wrb-buy"> 海外通販で買えるものだけ</label>
		<label><input type="checkbox" class="wrb-arch"> 過去アーカイブ（<?php echo $archived; ?>件）も表示</label>
	</div>
</div>

<div class="wrb-list">
<?php foreach ( $items as $x ) :
	if ( ! empty( $x['name'] ) ) {
		$title = $x['name'];
	} else {
		$title = trim( preg_replace( '/[（(].*?[）)]/u', '', $x['distillery'] ) );
		if ( $x['age'] ) $title .= ' ' . $x['age'] . '年';
		if ( $x['vintage'] ) $title .= ' ' . $x['vintage'];
	}
	$tlen  = mb_strlen( $title );
	$tsize = $tlen > 18 ? ' is-l' : ( $tlen > 12 ? ' is-m' : '' );
	$keys = array();
	if ( $x['abv'] ) $keys[] = rtrim( rtrim( number_format( $x['abv'], 1 ), '0' ), '.' ) . '%';
	if ( $x['bottles'] ) $keys[] = number_format( $x['bottles'] ) . '本限定';
	if ( $x['size_ml'] ) $keys[] = $x['size_ml'] . 'ml';
	if ( $x['region'] ) $keys[] = $x['region'];
	if ( ! empty( $x['collab'] ) ) $keys[] = '提携：' . $x['collab'];
	$detail = trim( ( $x['cask'] ? '樽：' . $x['cask'] . '。' : '' ) . $x['note'] );
	$date   = $x['announced'] ? wr_bottlers_fmt_date( $x['announced'] ) . ' 発表' : wr_bottlers_fmt_date( $x['first_seen'] ) . ' 掲載';
	$search = mb_strtolower( implode( ' ', array( $title, $x['_bottler'], $x['series'], $x['distillery'], $x['region'], $x['cask'], $x['note'] ) ) );
	$price  = wr_bottlers_price( $x['price'], $fx );
	?>
	<a class="wrb-card<?php echo $x['_archived'] ? ' is-arch' : ''; ?>" href="<?php echo esc_url( $x['source_url'] ); ?>" target="_blank" rel="noopener nofollow" data-jp="<?php echo esc_attr( $x['jp_status'] ); ?>" data-buy="<?php echo esc_attr( $x['buy_status'] ); ?>" data-b="<?php echo esc_attr( $x['_bottler'] ); ?>" data-r="<?php echo esc_attr( $x['region'] ); ?>" data-arch="<?php echo $x['_archived'] ? '1' : '0'; ?>" data-s="<?php echo esc_attr( $search ); ?>"<?php echo $x['_archived'] ? ' hidden' : ''; ?>>
		<div class="wrb-media">
			<div class="wrb-tile" style="--tc:<?php echo esc_attr( wr_bottlers_region_color( $x['region'] ) ); ?>"></div>
			<div class="wrb-cap"><span class="wrb-nm<?php echo $tsize; ?>"><?php echo esc_html( $title ); ?></span><span class="wrb-by"><?php echo esc_html( $x['_bottler'] ); ?></span></div>
			<?php if ( $x['_archived'] ) : ?><span class="wrb-flag is-arch">アーカイブ</span><?php elseif ( ( strtotime( $today ) - strtotime( $x['_base'] ) ) / 86400 <= 14 ) : ?><span class="wrb-flag">NEW</span><?php endif; ?>
		</div>
		<div class="wrb-body">
			<div class="wrb-meta"><span><?php echo esc_html( $x['series'] ? $x['series'] : '' ); ?></span><span><?php echo esc_html( $date ); ?></span></div>
			<?php if ( $keys ) : ?><div class="wrb-keys"><?php foreach ( $keys as $k ) : ?><span><?php echo esc_html( $k ); ?></span><?php endforeach; ?></div><?php endif; ?>
			<?php if ( $detail ) : ?><div class="wrb-cask"><?php echo esc_html( $detail ); ?></div><?php endif; ?>
			<?php if ( $price ) : ?><div class="wrb-price"><b><?php echo esc_html( $price[0] ); ?></b><?php if ( $price[1] ) : ?><small><?php echo esc_html( $price[1] ); ?></small><?php endif; ?></div>
			<?php else : ?><div class="wrb-price is-na"><b>公式価格 未発表</b></div><?php endif; ?>
			<?php if ( ! empty( $x['jp_price'] ) ) : ?><div class="wrb-series">国内公式価格 ¥<?php echo number_format( $x['jp_price'] ); ?>（税込）</div><?php endif; ?>
			<div class="wrb-judge">
				<div><span class="wrb-dot <?php echo esc_attr( $x['jp_status'] ); ?>"></span><span>国内：<?php echo esc_html( $L['jp_status'][ $x['jp_status'] ] ); ?><?php if ( $x['jp_note'] ) : ?><em><?php echo esc_html( $x['jp_note'] ); ?></em><?php endif; ?></span></div>
				<div><span class="wrb-dot <?php echo esc_attr( $x['buy_status'] ); ?>"></span><span>個人輸入：<?php echo esc_html( $L['buy_status'][ $x['buy_status'] ] ); ?><?php if ( $x['buy_note'] ) : ?><em><?php echo esc_html( $x['buy_note'] ); ?></em><?php endif; ?></span></div>
			</div>
			<div class="wrb-go">元記事を見る（<?php echo esc_html( $x['source_name'] ); ?>）→</div>
		</div>
	</a>
<?php endforeach; ?>
</div>
<p class="wrb-empty" hidden>条件に合うリリースはありません。</p>

<div class="wrb-small">
	<p>世界のインディペンデントボトラーが公式に発表したリリースを、輸入元や販売店に偏らず掲載しています。蒸留所のオフィシャル、酒販店・バー・イベント向けの限定ボトルは掲載していません。カードを押すと元記事が開きます。</p>
	<p>カード上部の色は地域を表しています：<?php foreach ( array( 'アイラ', 'スペイサイド', 'ハイランド', 'アイランズ', 'ローランド', 'キャンベルタウン', 'グレーン' ) as $rg ) : ?><span class="wrb-lg" style="--tc:<?php echo esc_attr( wr_bottlers_region_color( $rg ) ); ?>"><?php echo esc_html( $rg ); ?></span><?php endforeach; ?><span class="wrb-lg" style="--tc:#630000">その他</span></p>
	<p>価格はボトラーの公式価格です。円換算は<?php echo esc_html( wr_bottlers_fmt_date( isset( $fx['date'] ) ? $fx['date'] : '' ) ); ?>時点のレートによる目安で、送料・関税・酒税・消費税は含みません。「海外通販で販売中」は販売を確認したもので、日本への発送可否は各店でご確認ください。</p>
	<?php if ( $tier_b ) : ?><p>次のボトラーは公式発表が限られるため、一部のリリースのみ掲載しています：<?php echo esc_html( implode( '、', $tier_b ) ); ?></p><?php endif; ?>
	<p>20歳未満の飲酒は法律で禁止されています。</p>
</div>

<script>
(function(){
	var root=document.getElementById('wrb');if(!root)return;
	var tabs=root.querySelectorAll('.wrb-tab'),cards=root.querySelectorAll('.wrb-card'),empty=root.querySelector('.wrb-empty');
	var b=root.querySelector('.wrb-b'),r=root.querySelector('.wrb-r'),q=root.querySelector('.wrb-q'),buy=root.querySelector('.wrb-buy'),arch=root.querySelector('.wrb-arch');
	var jp='all';
	function apply(){
		var k=q.value.trim().toLowerCase(),n=0;
		cards.forEach(function(c){
			var d=c.dataset,ok=(arch.checked||d.arch==='0')&&(jp==='all'||d.jp===jp)&&(!b.value||d.b===b.value)&&(!r.value||d.r===r.value)&&(!buy.checked||d.buy==='buy_online')&&(!k||d.s.indexOf(k)>-1);
			c.hidden=!ok;if(ok)n++;
		});
		empty.hidden=n>0;
	}
	tabs.forEach(function(t){t.addEventListener('click',function(){jp=t.dataset.jp;tabs.forEach(function(x){x.setAttribute('aria-pressed',x===t?'true':'false');});apply();});});
	[b,r,buy,arch].forEach(function(el){el.addEventListener('change',apply);});
	q.addEventListener('input',apply);
})();
</script>
</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'bottlers_list', 'wr_bottlers_shortcode' );
