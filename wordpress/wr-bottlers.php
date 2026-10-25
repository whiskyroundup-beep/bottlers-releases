<?php
/**
 * Whisky Roundup ボトラーズ最新リリース一覧
 * 使い方：Code Snippets プラグインに貼り付けて有効化 → 固定ページに [bottlers_list] を置く
 * データ：GitHub の releases.json（毎週自動更新）を6時間キャッシュして表示
 */

if ( ! defined( 'WR_BOTTLERS_JSON' ) ) {
	define( 'WR_BOTTLERS_JSON', 'https://raw.githubusercontent.com/whiskyroundup-beep/bottlers-releases/main/data/releases.json' );
}

function wr_bottlers_get_data() {
	$cache = get_transient( 'wr_bottlers_data' );
	if ( $cache !== false ) {
		return $cache;
	}
	$res = wp_remote_get( WR_BOTTLERS_JSON, array( 'timeout' => 10 ) );
	if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) {
		$backup = get_option( 'wr_bottlers_backup' );
		return $backup ? $backup : null;
	}
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( ! is_array( $data ) || empty( $data['releases'] ) ) {
		$backup = get_option( 'wr_bottlers_backup' );
		return $backup ? $backup : null;
	}
	set_transient( 'wr_bottlers_data', $data, 6 * HOUR_IN_SECONDS );
	update_option( 'wr_bottlers_backup', $data, false ); // 取得失敗時の保険
	return $data;
}

function wr_bottlers_fmt_date( $d ) {
	if ( ! $d ) return '';
	$p = explode( '-', $d );
	if ( count( $p ) === 3 ) return intval( $p[0] ) . '年' . intval( $p[1] ) . '月' . intval( $p[2] ) . '日';
	if ( count( $p ) === 2 ) return intval( $p[0] ) . '年' . intval( $p[1] ) . '月';
	return $d . '年';
}

function wr_bottlers_shortcode() {
	$data = wr_bottlers_get_data();
	if ( ! $data ) {
		return '<p>リリース情報を読み込めませんでした。時間をおいて再度ご確認ください。</p>';
	}
	$labels   = $data['statuses'];
	$releases = $data['releases'];

	// 並び順：発売予定 → 発売済み（日付の新しい順）→ 海外
	$order = array( 'upcoming' => 0, 'released' => 1, 'overseas' => 2 );
	usort( $releases, function ( $a, $b ) use ( $order ) {
		$s = $order[ $a['status'] ] - $order[ $b['status'] ];
		if ( $s !== 0 ) return $s;
		return strcmp( (string) $b['release_date'], (string) $a['release_date'] );
	} );

	$counts = array_count_values( array_column( $releases, 'status' ) );
	$bottlers = array_unique( array_column( $releases, 'bottler' ) );
	sort( $bottlers );
	$regions = array_unique( array_filter( array_column( $releases, 'region' ) ) );
	sort( $regions );

	ob_start();
	?>
<div class="wrb" id="wrb">
<style>
.wrb{--wrb-ink:#2a1a12;--wrb-sub:#6b5a4e;--wrb-line:#e3d9c6;--wrb-bg:#faf8f2;--wrb-card:#fff;--wrb-brand:#630000;--wrb-cream:#eeebdd;--wrb-rose:#d8b6a4;--wrb-up:#8c2a2e;--wrb-ov:#3b5b6b;color:var(--wrb-ink);font-size:15px;line-height:1.6}
.wrb *{box-sizing:border-box}
.wrb [hidden]{display:none!important}
.wrb-meta{display:flex;flex-wrap:wrap;gap:6px 16px;align-items:baseline;margin:0 0 14px;color:var(--wrb-sub);font-size:13px}
.wrb-meta strong{color:var(--wrb-brand);font-size:15px}
.wrb-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 10px}
.wrb-tab{border:1px solid var(--wrb-line);background:var(--wrb-card);color:var(--wrb-ink);border-radius:999px;padding:6px 14px;font-size:13px;cursor:pointer;line-height:1.3}
.wrb-tab span{color:var(--wrb-sub);margin-left:4px;font-variant-numeric:tabular-nums}
.wrb-tab[aria-pressed="true"]{background:var(--wrb-brand);border-color:var(--wrb-brand);color:#fff}
.wrb-tab[aria-pressed="true"] span{color:var(--wrb-rose)}
.wrb-filters{display:grid;grid-template-columns:1fr 1fr 1.4fr;gap:8px;margin:0 0 16px}
.wrb-filters select,.wrb-filters input{width:100%;border:1px solid var(--wrb-line);border-radius:6px;padding:8px 10px;font-size:14px;background:var(--wrb-card);color:var(--wrb-ink)}
.wrb-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:12px}
.wrb-card{background:var(--wrb-card);border:1px solid var(--wrb-line);border-radius:10px;padding:14px 14px 12px;display:flex;flex-direction:column;gap:8px}
.wrb-top{display:flex;justify-content:space-between;align-items:center;gap:8px}
.wrb-badge{font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px;background:var(--wrb-cream);color:var(--wrb-brand);white-space:nowrap}
.wrb-badge.is-upcoming{background:var(--wrb-up);color:#fff}
.wrb-badge.is-overseas{background:#e4ecef;color:var(--wrb-ov)}
.wrb-date{font-size:12px;color:var(--wrb-sub);text-align:right}
.wrb-bottler{font-size:12px;color:var(--wrb-brand);font-weight:700;letter-spacing:.02em}
.wrb-name{font-size:17px;font-weight:700;margin:0;line-height:1.35}
.wrb-name small{display:block;font-size:12px;font-weight:400;color:var(--wrb-sub);margin-top:2px}
.wrb-spec{display:flex;flex-wrap:wrap;gap:4px}
.wrb-spec li{list-style:none;font-size:12px;border:1px solid var(--wrb-line);border-radius:4px;padding:1px 6px;font-variant-numeric:tabular-nums}
.wrb-spec{margin:0;padding:0}
.wrb-cask{font-size:12px;color:var(--wrb-sub)}
.wrb-foot{margin-top:auto;padding-top:8px;border-top:1px dashed var(--wrb-line);display:flex;justify-content:space-between;align-items:flex-end;gap:8px}
.wrb-price{font-size:16px;font-weight:700;font-variant-numeric:tabular-nums}
.wrb-price.is-na{font-size:12px;font-weight:400;color:var(--wrb-sub)}
.wrb-where{font-size:12px;color:var(--wrb-sub)}
.wrb-src{font-size:12px;white-space:nowrap}
.wrb-src a{color:var(--wrb-brand)}
.wrb-note{font-size:12px;color:var(--wrb-sub);background:var(--wrb-bg);border-radius:4px;padding:4px 8px}
.wrb-empty{padding:30px;text-align:center;color:var(--wrb-sub)}
.wrb-disclaimer{font-size:12px;color:var(--wrb-sub);margin-top:16px}
@media (max-width:640px){.wrb-filters{grid-template-columns:1fr 1fr}.wrb-filters .wrb-q{grid-column:1/-1}.wrb-list{grid-template-columns:1fr}}
</style>

<div class="wrb-meta"><span>掲載 <strong><?php echo count( $releases ); ?></strong> 件</span><span>最終更新：<?php echo esc_html( wr_bottlers_fmt_date( $data['updated'] ) ); ?></span><span>毎週更新</span></div>

<div class="wrb-tabs" role="group" aria-label="発売状況で絞り込む">
	<button type="button" class="wrb-tab" data-status="all" aria-pressed="true">すべて<span><?php echo count( $releases ); ?></span></button>
	<?php foreach ( array( 'upcoming', 'released', 'overseas' ) as $s ) : ?>
	<button type="button" class="wrb-tab" data-status="<?php echo $s; ?>" aria-pressed="false"><?php echo esc_html( $labels[ $s ] ); ?><span><?php echo isset( $counts[ $s ] ) ? $counts[ $s ] : 0; ?></span></button>
	<?php endforeach; ?>
</div>

<div class="wrb-filters">
	<select class="wrb-bottler-sel" aria-label="ボトラーで絞り込む"><option value="">すべてのボトラー</option>
	<?php foreach ( $bottlers as $b ) : ?><option><?php echo esc_html( $b ); ?></option><?php endforeach; ?></select>
	<select class="wrb-region-sel" aria-label="地域で絞り込む"><option value="">すべての地域</option>
	<?php foreach ( $regions as $r ) : ?><option><?php echo esc_html( $r ); ?></option><?php endforeach; ?></select>
	<input type="search" class="wrb-q" placeholder="蒸留所名・キーワードで検索" aria-label="キーワード検索">
</div>

<div class="wrb-list">
<?php foreach ( $releases as $x ) :
	$title = $x['distillery'];
	if ( $x['age'] ) $title .= ' ' . $x['age'] . '年';
	if ( $x['vintage'] ) $title .= ' ' . $x['vintage'];
	$specs = array();
	if ( $x['abv'] ) $specs[] = rtrim( rtrim( number_format( $x['abv'], 1 ), '0' ), '.' ) . '%';
	if ( $x['bottles'] ) $specs[] = '限定' . number_format( $x['bottles'] ) . '本';
	if ( $x['region'] ) $specs[] = $x['region'];
	if ( $x['jp_distributor'] && ! in_array( $x['jp_distributor'], array( '—', '未確認' ), true ) ) $specs[] = $x['jp_distributor'];
	$search = implode( ' ', array( $x['bottler'], $x['series'], $x['distillery'], $x['region'], $x['cask'], $x['where_to_buy'], $x['note'] ) );
	$date = $x['release_date'] ? wr_bottlers_fmt_date( $x['release_date'] ) . ' ' . $x['date_note'] : $x['date_note'];
	?>
	<article class="wrb-card" data-status="<?php echo esc_attr( $x['status'] ); ?>" data-bottler="<?php echo esc_attr( $x['bottler'] ); ?>" data-region="<?php echo esc_attr( $x['region'] ); ?>" data-search="<?php echo esc_attr( mb_strtolower( $search ) ); ?>">
		<div class="wrb-top"><span class="wrb-badge is-<?php echo esc_attr( $x['status'] ); ?>"><?php echo esc_html( $labels[ $x['status'] ] ); ?></span><span class="wrb-date"><?php echo esc_html( $date ); ?></span></div>
		<div class="wrb-bottler"><?php echo esc_html( $x['bottler'] ); ?></div>
		<h3 class="wrb-name"><?php echo esc_html( $title ); ?><?php if ( $x['series'] ) : ?><small><?php echo esc_html( $x['series'] ); ?></small><?php endif; ?></h3>
		<?php if ( $specs ) : ?><ul class="wrb-spec"><?php foreach ( $specs as $s ) : ?><li><?php echo esc_html( $s ); ?></li><?php endforeach; ?></ul><?php endif; ?>
		<?php if ( $x['cask'] ) : ?><div class="wrb-cask">樽：<?php echo esc_html( $x['cask'] ); ?></div><?php endif; ?>
		<?php if ( $x['note'] ) : ?><div class="wrb-note"><?php echo esc_html( $x['note'] ); ?></div><?php endif; ?>
		<div class="wrb-foot">
			<div>
				<?php if ( $x['price_jpy'] ) : ?><div class="wrb-price">¥<?php echo number_format( $x['price_jpy'] ); ?><small style="font-size:11px;font-weight:400">（税込）</small></div>
				<?php else : ?><div class="wrb-price is-na">国内価格 未発表</div><?php endif; ?>
				<?php if ( $x['where_to_buy'] && $x['where_to_buy'] !== '—' ) : ?><div class="wrb-where"><?php echo esc_html( $x['where_to_buy'] ); ?></div><?php endif; ?>
			</div>
			<div class="wrb-src"><a href="<?php echo esc_url( $x['source_url'] ); ?>" target="_blank" rel="noopener nofollow">出典：<?php echo esc_html( $x['source_name'] ); ?></a></div>
		</div>
	</article>
<?php endforeach; ?>
</div>
<p class="wrb-empty" hidden>条件に合うリリースはありません。</p>
<p class="wrb-disclaimer">※価格・在庫・発売日は各販売店・輸入元の発表時点の情報です。購入前に必ず販売店でご確認ください。「海外発売・国内入荷見込み」は国内で流通実績のあるボトラーの海外リリースで、国内入荷を保証するものではありません。20歳未満の飲酒は法律で禁止されています。</p>

<script>
(function(){
	var root=document.getElementById('wrb');if(!root)return;
	var tabs=root.querySelectorAll('.wrb-tab'),cards=root.querySelectorAll('.wrb-card');
	var bSel=root.querySelector('.wrb-bottler-sel'),rSel=root.querySelector('.wrb-region-sel'),q=root.querySelector('.wrb-q'),empty=root.querySelector('.wrb-empty');
	var status='all';
	function apply(){
		var b=bSel.value,r=rSel.value,k=q.value.trim().toLowerCase(),n=0;
		cards.forEach(function(c){
			var ok=(status==='all'||c.dataset.status===status)&&(!b||c.dataset.bottler===b)&&(!r||c.dataset.region===r)&&(!k||c.dataset.search.indexOf(k)>-1);
			c.hidden=!ok;if(ok)n++;
		});
		empty.hidden=n>0;
	}
	tabs.forEach(function(t){t.addEventListener('click',function(){status=t.dataset.status;tabs.forEach(function(x){x.setAttribute('aria-pressed',x===t?'true':'false');});apply();});});
	[bSel,rSel].forEach(function(s){s.addEventListener('change',apply);});
	q.addEventListener('input',apply);
})();
</script>
</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'bottlers_list', 'wr_bottlers_shortcode' );
