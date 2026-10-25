<?php
/**
 * Whisky Roundup ボトラーズ最新リリース（世界版）
 * 使い方：Code Snippets に「PHP」タイプで貼り付けて有効化 → 固定ページに [bottlers_list]
 * データ：GitHub の data/releases.json（毎週自動更新）を6時間キャッシュ
 * 要件：REQUIREMENTS.md
 */

if ( ! defined( 'WR_BOTTLERS_JSON' ) ) {
	define( 'WR_BOTTLERS_JSON', 'https://raw.githubusercontent.com/whiskyroundup-beep/bottlers-releases/main/data/releases.json' );
}

function wr_bottlers_get_data() {
	$cache = get_transient( 'wr_bottlers_data_v2' );
	if ( $cache !== false ) {
		return $cache;
	}
	$res  = wp_remote_get( WR_BOTTLERS_JSON, array( 'timeout' => 10 ) );
	$data = null;
	if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
	}
	if ( ! is_array( $data ) || ! isset( $data['releases'] ) ) {
		$backup = get_option( 'wr_bottlers_backup_v2' );
		return $backup ? $backup : null;
	}
	set_transient( 'wr_bottlers_data_v2', $data, 6 * HOUR_IN_SECONDS );
	update_option( 'wr_bottlers_backup_v2', $data, false );
	return $data;
}

function wr_bottlers_fmt_date( $d ) {
	if ( ! $d ) return '';
	$p = explode( '-', $d );
	if ( count( $p ) === 3 ) return intval( $p[0] ) . '年' . intval( $p[1] ) . '月' . intval( $p[2] ) . '日';
	if ( count( $p ) === 2 ) return intval( $p[0] ) . '年' . intval( $p[1] ) . '月';
	return $d . '年';
}

function wr_bottlers_fmt_price( $price, $fx ) {
	if ( empty( $price ) || empty( $price['amount'] ) ) return '';
	$sym = array( 'GBP' => '£', 'USD' => '$', 'EUR' => '€', 'JPY' => '¥' );
	$c   = $price['currency'];
	$a   = $price['amount'];
	$out = ( isset( $sym[ $c ] ) ? $sym[ $c ] : $c . ' ' ) . number_format( $a, ( floor( $a ) == $a ) ? 0 : 2 );
	if ( $c !== 'JPY' && ! empty( $fx[ $c ] ) ) {
		$yen = round( $a * $fx[ $c ], -2 );
		$out .= '<small>（約' . number_format( $yen ) . '円）</small>';
	}
	return $out;
}

function wr_bottlers_shortcode() {
	$data = wr_bottlers_get_data();
	if ( ! $data ) {
		return '<p>リリース情報を読み込めませんでした。時間をおいて再度ご確認ください。</p>';
	}
	$L        = $data['labels'];
	$fx       = isset( $data['fx'] ) ? $data['fx'] : array();
	$days     = isset( $data['archive_days'] ) ? intval( $data['archive_days'] ) : 90;
	$today    = current_time( 'Y-m-d' );
	$bmap     = array();
	foreach ( $data['bottlers'] as $b ) $bmap[ $b['id'] ] = $b;

	$items = array();
	foreach ( $data['releases'] as $x ) {
		$base = $x['announced'] ? $x['announced'] : $x['first_seen'];
		if ( strlen( $base ) === 7 ) $base .= '-01';
		$age_days = ( strtotime( $today ) - strtotime( $base ) ) / 86400;
		$x['_base']     = $base;
		$x['_archived'] = ( $age_days > $days ) && empty( $x['jp_pending'] );
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

	ob_start();
	?>
<div class="wrb" id="wrb">
<style>
.wrb{--ink:#2a1a12;--sub:#6b5a4e;--line:#e3d9c6;--soft:#faf8f2;--card:#fff;--brand:#630000;--cream:#eeebdd;--rose:#d8b6a4;color:var(--ink);font-size:15px;line-height:1.6}
.wrb *{box-sizing:border-box}.wrb [hidden]{display:none!important}
.wrb-meta{display:flex;flex-wrap:wrap;gap:4px 16px;align-items:baseline;margin:0 0 14px;color:var(--sub);font-size:13px}
.wrb-meta strong{color:var(--brand);font-size:15px}
.wrb-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 10px}
.wrb-tab{border:1px solid var(--line);background:var(--card);color:var(--ink);border-radius:999px;padding:6px 13px;font-size:13px;cursor:pointer;line-height:1.3}
.wrb-tab span{color:var(--sub);margin-left:4px;font-variant-numeric:tabular-nums}
.wrb-tab[aria-pressed="true"]{background:var(--brand);border-color:var(--brand);color:#fff}
.wrb-tab[aria-pressed="true"] span{color:var(--rose)}
.wrb-filters{display:grid;grid-template-columns:1fr 1fr 1.4fr;gap:8px;margin:0 0 8px}
.wrb-filters select,.wrb-filters input[type=search]{width:100%;border:1px solid var(--line);border-radius:6px;padding:8px 10px;font-size:14px;background:var(--card);color:var(--ink)}
.wrb-checks{display:flex;flex-wrap:wrap;gap:6px 18px;margin:0 0 16px;font-size:13px;color:var(--sub)}
.wrb-checks label{display:flex;gap:6px;align-items:center;cursor:pointer}
.wrb-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px}
.wrb-card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:14px 14px 12px;display:flex;flex-direction:column;gap:8px}
.wrb-card.is-arch{background:var(--soft)}
.wrb-top{display:flex;justify-content:space-between;align-items:baseline;gap:8px;font-size:12px;color:var(--sub)}
.wrb-bottler{font-weight:700;color:var(--brand);letter-spacing:.02em}
.wrb-name{font-size:17px;font-weight:700;margin:0;line-height:1.35}
.wrb-name small{display:block;font-size:12px;font-weight:400;color:var(--sub);margin-top:2px}
.wrb-spec{display:flex;flex-wrap:wrap;gap:4px;margin:0;padding:0}
.wrb-spec li{list-style:none;font-size:12px;border:1px solid var(--line);border-radius:4px;padding:1px 6px;font-variant-numeric:tabular-nums}
.wrb-cask,.wrb-note{font-size:12px;color:var(--sub)}
.wrb-note{background:var(--soft);border-radius:4px;padding:4px 8px}
.wrb-judge{display:grid;gap:4px;font-size:12px}
.wrb-judge div{display:flex;flex-wrap:wrap;gap:2px 6px;align-items:baseline}
.wrb-pill{flex:none;font-size:11px;font-weight:700;padding:1px 7px;border-radius:4px;white-space:nowrap}
.wrb-pill.jp_official{background:var(--brand);color:#fff}.wrb-pill.jp_route{background:var(--cream);color:var(--brand)}
.wrb-pill.jp_parallel{background:#f3e3cf;color:#7a4a14}.wrb-pill.jp_unknown,.wrb-pill.jp_none,.wrb-pill.buy_unknown{background:#efefef;color:#666}
.wrb-pill.buy_online{background:#e4ecef;color:#2f5566}
.wrb-judge span:last-child{color:var(--sub)}
.wrb-foot{margin-top:auto;padding-top:8px;border-top:1px dashed var(--line);display:flex;justify-content:space-between;align-items:flex-end;gap:8px}
.wrb-price{font-size:16px;font-weight:700;font-variant-numeric:tabular-nums}
.wrb-price small{font-size:11px;font-weight:400;color:var(--sub);margin-left:2px}
.wrb-price.is-na{font-size:12px;font-weight:400;color:var(--sub)}
.wrb-src{font-size:12px;text-align:right}.wrb-src a{color:var(--brand)}
.wrb-empty{padding:30px;text-align:center;color:var(--sub)}
.wrb-small{font-size:12px;color:var(--sub);margin-top:16px}
@media (max-width:640px){.wrb-filters{grid-template-columns:1fr 1fr}.wrb-filters .wrb-q{grid-column:1/-1}.wrb-list{grid-template-columns:1fr}}
</style>

<div class="wrb-meta">
	<span>掲載 <strong><?php echo count( $active ); ?></strong> 件</span>
	<span>過去アーカイブ <?php echo $archived; ?> 件</span>
	<span>最終更新：<?php echo esc_html( wr_bottlers_fmt_date( $data['updated'] ) ); ?>（毎週更新）</span>
</div>

<div class="wrb-tabs" role="group" aria-label="国内導入で絞り込む">
	<button type="button" class="wrb-tab" data-jp="all" aria-pressed="true">すべて<span><?php echo count( $active ); ?></span></button>
	<?php foreach ( $jp_order as $k ) : if ( empty( $jp_count[ $k ] ) ) continue; ?>
	<button type="button" class="wrb-tab" data-jp="<?php echo $k; ?>" aria-pressed="false">国内：<?php echo esc_html( $L['jp_status'][ $k ] ); ?><span><?php echo $jp_count[ $k ]; ?></span></button>
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
	<label><input type="checkbox" class="wrb-buy"> 海外通販で販売中のみ</label>
	<label><input type="checkbox" class="wrb-arch"> 過去アーカイブも表示</label>
</div>

<div class="wrb-list">
<?php foreach ( $items as $x ) :
	$title = $x['distillery'];
	if ( $x['age'] ) $title .= ' ' . $x['age'] . '年';
	if ( $x['vintage'] ) $title .= ' ' . $x['vintage'];
	$specs = array();
	if ( $x['abv'] ) $specs[] = rtrim( rtrim( number_format( $x['abv'], 1 ), '0' ), '.' ) . '%';
	if ( $x['bottles'] ) $specs[] = number_format( $x['bottles'] ) . '本';
	if ( $x['size_ml'] ) $specs[] = $x['size_ml'] . 'ml';
	if ( $x['region'] ) $specs[] = $x['region'];
	if ( ! empty( $x['collab'] ) ) $specs[] = '提携：' . $x['collab'];
	$date   = $x['announced'] ? wr_bottlers_fmt_date( $x['announced'] ) . ' 発表' : wr_bottlers_fmt_date( $x['first_seen'] ) . ' 掲載確認';
	$search = mb_strtolower( implode( ' ', array( $x['_bottler'], $x['series'], $x['distillery'], $x['region'], $x['cask'], $x['note'] ) ) );
	$price  = wr_bottlers_fmt_price( $x['price'], $fx );
	?>
	<article class="wrb-card<?php echo $x['_archived'] ? ' is-arch' : ''; ?>" data-jp="<?php echo esc_attr( $x['jp_status'] ); ?>" data-buy="<?php echo esc_attr( $x['buy_status'] ); ?>" data-b="<?php echo esc_attr( $x['_bottler'] ); ?>" data-r="<?php echo esc_attr( $x['region'] ); ?>" data-arch="<?php echo $x['_archived'] ? '1' : '0'; ?>" data-s="<?php echo esc_attr( $search ); ?>"<?php echo $x['_archived'] ? ' hidden' : ''; ?>>
		<div class="wrb-top"><span class="wrb-bottler"><?php echo esc_html( $x['_bottler'] ); ?></span><span><?php echo esc_html( $date ); ?><?php echo $x['_archived'] ? '（アーカイブ）' : ''; ?></span></div>
		<h3 class="wrb-name"><?php echo esc_html( $title ); ?><?php if ( $x['series'] ) : ?><small><?php echo esc_html( $x['series'] ); ?></small><?php endif; ?></h3>
		<?php if ( $specs ) : ?><ul class="wrb-spec"><?php foreach ( $specs as $s ) : ?><li><?php echo esc_html( $s ); ?></li><?php endforeach; ?></ul><?php endif; ?>
		<?php if ( $x['cask'] ) : ?><div class="wrb-cask">樽：<?php echo esc_html( $x['cask'] ); ?></div><?php endif; ?>
		<?php if ( $x['note'] ) : ?><div class="wrb-note"><?php echo esc_html( $x['note'] ); ?></div><?php endif; ?>
		<div class="wrb-judge">
			<div><span class="wrb-pill <?php echo esc_attr( $x['jp_status'] ); ?>">国内導入：<?php echo esc_html( $L['jp_status'][ $x['jp_status'] ] ); ?></span><span><?php echo esc_html( $x['jp_note'] ); ?></span></div>
			<div><span class="wrb-pill <?php echo esc_attr( $x['buy_status'] ); ?>">個人輸入：<?php echo esc_html( $L['buy_status'][ $x['buy_status'] ] ); ?></span><span><?php echo esc_html( $x['buy_note'] ); ?></span></div>
		</div>
		<div class="wrb-foot">
			<div>
				<?php if ( $price ) : ?><div class="wrb-price"><?php echo $price; ?></div><?php else : ?><div class="wrb-price is-na">公式価格 未発表</div><?php endif; ?>
				<?php if ( ! empty( $x['jp_price'] ) ) : ?><div class="wrb-cask">国内公式価格：¥<?php echo number_format( $x['jp_price'] ); ?>（税込）</div><?php endif; ?>
			</div>
			<div class="wrb-src"><a href="<?php echo esc_url( $x['source_url'] ); ?>" target="_blank" rel="noopener nofollow">出典：<?php echo esc_html( $x['source_name'] ); ?></a></div>
		</div>
	</article>
<?php endforeach; ?>
</div>
<p class="wrb-empty" hidden>条件に合うリリースはありません。</p>

<div class="wrb-small">
	<p>このページは、世界のインディペンデントボトラーが公式に発表したリリースを、輸入元や販売店に偏らず掲載しています。蒸留所のオフィシャル、酒販店・バー・イベント向けの限定ボトルは掲載していません。</p>
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
