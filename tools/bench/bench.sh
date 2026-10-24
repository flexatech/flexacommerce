#!/usr/bin/env bash
# T3 benchmark driver (doc 10 §5.3, §6). Everything runs inside the isolated
# docker project "fxc-bench"; nothing here touches a real site.
#
#   ./bench.sh up                  build and start the stack
#   ./bench.sh setup S|M           install core + both stores, load the dataset, check parity
#   ./bench.sh export S|M          export both stores again (parity check, URLs, cart IDs)
#   ./bench.sh run [scenario...]   3 alternating runs per scenario (default: blog-post home)
#
# Scenarios: blog-post, home, shop, simple, variable (GET pages) and cart-add,
# cart-view (store REST APIs; the store database is restored from out/dumps
# before each cart scenario). core runs blog-post and home only.
#   ./bench.sh summary <dir>       summarize a run directory
#   ./bench.sh down                stop the stack (volumes are kept)
#
# Env: PHP_VERSION (8.2), FPM_WORKERS (4), VUS (1), N (500), WARMUP (50), RUNS (3).
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
COMPOSE=(docker compose -f "$HERE/docker/compose.yml")
WP_VERSION=7.1.3
SEED=42
export PHP_VERSION="${PHP_VERSION:-8.2}" FPM_WORKERS="${FPM_WORKERS:-4}"
VUS="${VUS:-1}" N="${N:-500}" WARMUP="${WARMUP:-50}" RUNS="${RUNS:-3}"

php_exec() { "${COMPOSE[@]}" exec -T php bash -c "$1"; }
wp() { php_exec "php -d memory_limit=2G /usr/local/bin/wp --allow-root --path=/var/www/html $*"; }
sql() { php_exec "mysql -h db -uroot -pbench --skip-ssl -N -e \"$1\" 2>/dev/null"; }

cmd_up() {
	"${COMPOSE[@]}" build php
	"${COMPOSE[@]}" up -d db php nginx
	php_exec 'chmod 777 /var/log/bench'
}

install_store() { # host db
	local host=$1 db=$2
	sql "DROP DATABASE IF EXISTS $db; CREATE DATABASE $db CHARACTER SET utf8mb4;"
	wp core install --url=http://$host --title=Bench --admin_user=admin --admin_password=bench \
		--admin_email=bench@example.test --skip-email
	wp --url=http://$host theme activate twentytwentyfive
	wp --url=http://$host rewrite structure "'/%postname%/'" --hard
	wp --url=http://$host post create --post_type=post --post_status=publish \
		--post_title="'Benchmark post'" --post_name=benchmark-post \
		--post_content="'$(post_content)'"
}

# The same blog post in every store: a few paragraphs, a heading and a list.
post_content() {
	printf '%s' '<!-- wp:paragraph --><p>This post is the benchmark page without commerce content. It has a heading, a list and a few paragraphs, like a typical article.</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">A heading</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p><!-- /wp:paragraph --><!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>First item</li><!-- /wp:list-item --><!-- wp:list-item --><li>Second item</li><!-- /wp:list-item --></ul><!-- /wp:list -->'
}

cmd_setup() {
	local size=${1:?size S or M}
	local spec=/bench/out/dataset-$size-$SEED.json
	php_exec "[ -f $spec ] || php /bench/generate-dataset.php --size=$size --seed=$SEED --out=$spec"
	php_exec "[ -f /var/www/html/wp-load.php ] || php -d memory_limit=512M /usr/local/bin/wp --allow-root --path=/var/www/html core download --version=$WP_VERSION --locale=en_US"

	install_store fxc.bench bench_fxc
	wp --url=http://fxc.bench plugin activate flexacommerce
	wp --url=http://fxc.bench eval-file /bench/load-flexacommerce.php $spec

	install_store wc.bench bench_wc
	php_exec '[ -d /var/www/html/wp-content/plugins/woocommerce ] || php -d memory_limit=512M /usr/local/bin/wp --allow-root --path=/var/www/html plugin install woocommerce'
	wp --url=http://wc.bench plugin activate woocommerce
	# A new store starts in coming-soon mode, which hides the shop from visitors.
	wp --url=http://wc.bench option update woocommerce_coming_soon no
	wp --url=http://wc.bench eval-file /bench/load-woocommerce.php $spec

	cmd_export "$size"

	php_exec "mkdir -p /bench/out/dumps && for db in bench_fxc bench_wc; do mysqldump -h db -uroot -pbench --skip-ssl --single-transaction \$db 2>/dev/null | gzip > /bench/out/dumps/\$db-$size.sql.gz; done"
	{
		echo "size=$size seed=$SEED"
		echo "wordpress=$(wp --url=http://fxc.bench core version)"
		echo "woocommerce=$(wp --url=http://wc.bench plugin get woocommerce --field=version)"
		echo "flexacommerce=$(git -C "$HERE/../.." rev-parse --short HEAD 2>/dev/null || echo unknown)$(git -C "$HERE/../.." diff --quiet 2>/dev/null || echo '+dirty')"
		echo "php=$(php_exec 'php -r "echo PHP_VERSION;"')"
		echo "mysql=$(sql 'SELECT VERSION();')"
	} | tee "$HERE/out/setup.txt"
}

cmd_export() {
	local size=${1:?size S or M}
	local spec=/bench/out/dataset-$size-$SEED.json
	php_exec "php -d memory_limit=2G /usr/local/bin/wp --allow-root --path=/var/www/html --url=http://fxc.bench eval-file /bench/parity-export.php $spec /bench/out/parity-fxc.json"
	php_exec "php -d memory_limit=2G /usr/local/bin/wp --allow-root --path=/var/www/html --url=http://wc.bench eval-file /bench/parity-export.php $spec /bench/out/parity-wc.json"
	php_exec "php -d memory_limit=2G /bench/parity-compare.php $spec /bench/out/parity-fxc.json /bench/out/parity-wc.json" | tee "$HERE/out/parity.txt"
}

fpm_rss() { # KB per worker, one line each
	php_exec "ps --no-headers -o rss,args -C php-fpm | awk '/pool/ {print \$1}'"
}

mysql_status() {
	sql "SHOW GLOBAL STATUS WHERE Variable_name LIKE 'Handler_read%' OR Variable_name = 'Innodb_rows_read';"
}

k6() { # host path run n [api mode products]
	if [ $# -gt 4 ]; then
		"${COMPOSE[@]}" --profile tools run --rm -T \
			-e HOST="$1" -e RUN="$3" -e N="$4" -e VUS="$VUS" -e API="$5" -e MODE="$6" -e PRODUCTS="$7" \
			k6 run --quiet /scripts/cart.js >/dev/null
		return
	fi
	"${COMPOSE[@]}" --profile tools run --rm -T \
		-e HOST="$1" -e PATH_URI="$2" -e RUN="$3" -e N="$4" -e VUS="$VUS" \
		k6 run --quiet /scripts/page.js >/dev/null
}

export_get() { # store php-expression-on-$e
	local export=$HERE/out/parity-fxc.json
	[ "$1" = wc ] && export=$HERE/out/parity-wc.json
	php -r '$e = json_decode(file_get_contents($argv[1]), true); echo '"$2"';' "$export"
}

url_for() { # store scenario
	case $2 in
		cart-*) export_get "$1" '$e["urls"]["home"] ?? ""' ;;
		*) export_get "$1" '$e["urls"]["'"$2"'"] ?? ""' ;;
	esac
}

# Cart scenarios write sessions, so each one starts from the dump made at setup.
restore_db() { # store
	local db=bench_$1 size
	size=$(sed -n 's/^size=\([SM]\).*/\1/p' "$HERE/out/setup.txt")
	php_exec "gunzip < /bench/out/dumps/$db-$size.sql.gz | mysql -h db -uroot -pbench --skip-ssl $db 2>/dev/null"
}

cmd_run() {
	local scenarios=("$@")
	[ ${#scenarios[@]} -gt 0 ] || scenarios=(blog-post home)
	local stamp out stores=(core fxc wc)
	stamp=$(date -u +%Y%m%dT%H%M%SZ)
	out=$HERE/out/runs/$stamp
	mkdir -p "$out"
	cp "$HERE/out/setup.txt" "$HERE/out/parity.txt" "$out/" 2>/dev/null || true
	{
		echo "php_version=$PHP_VERSION fpm_workers=$FPM_WORKERS vus=$VUS n=$N warmup=$WARMUP runs=$RUNS"
		echo "host=$(uname -srm) cpus=$(sysctl -n hw.ncpu 2>/dev/null || nproc)"
		docker version --format 'docker={{.Server.Version}}'
		"${COMPOSE[@]}" images --format json 2>/dev/null | php -r 'foreach (json_decode(stream_get_contents(STDIN), true) ?: [] as $i) { echo $i["Repository"], ":", $i["Tag"], " ", substr($i["ID"], 0, 19), "\n"; }'
	} > "$out/environment.txt"

	for run in $(seq 1 "$RUNS"); do
		# Rotate the store order each run so drift over time hits every store.
		local order=("${stores[@]:$(( (run - 1) % 3 ))}" "${stores[@]:0:$(( (run - 1) % 3 ))}")
		for store in "${order[@]}"; do
			"${COMPOSE[@]}" restart php >/dev/null
			php_exec 'chmod 777 /var/log/bench'
			for scenario in "${scenarios[@]}"; do
				local path label dir cart=()
				# core has no catalog, so it only serves the pages without commerce content.
				[ "$store" != core ] || [ "$scenario" = blog-post ] || [ "$scenario" = home ] || continue
				if [[ $scenario == cart-* ]]; then
					cart=("$store" "${scenario#cart-}" "$(export_get "$store" 'implode(",", $e["cart_ids"] ?? [])')")
					restore_db "$store"
				fi
				path=$(url_for "$store" "$scenario")
				[ -n "$path" ] || { echo "no url for $store $scenario" >&2; exit 1; }
				label=$scenario.$store.$run
				dir=$out/$label
				mkdir -p "$dir"
				echo "$label ${cart[*]:-$path}"
				k6 "$store.bench" "$path" "$label-warm" "$WARMUP" ${cart[@]+"${cart[@]}"}
				php_exec ': > /var/log/bench/nginx.jsonl; : > /var/log/bench/php.jsonl'
				fpm_rss > "$dir/rss-before.txt"
				mysql_status > "$dir/mysql-before.txt"
				k6 "$store.bench" "$path" "$label" "$N" ${cart[@]+"${cart[@]}"}
				mysql_status > "$dir/mysql-after.txt"
				fpm_rss > "$dir/rss-after.txt"
				[ ${#cart[@]} -gt 0 ] || curl -s -o "$dir/page.html" -H "Host: $store.bench" "http://127.0.0.1:8089$path"
				php_exec 'cat /var/log/bench/nginx.jsonl' > "$dir/nginx.jsonl"
				php_exec 'cat /var/log/bench/php.jsonl' > "$dir/php.jsonl"
			done
		done
	done
	php "$HERE/summarize.php" "$out"
}

case "${1:-}" in
	up) cmd_up ;;
	setup) shift; cmd_setup "$@" ;;
	export) shift; cmd_export "$@" ;;
	run) shift; cmd_run "$@" ;;
	summary) php "$HERE/summarize.php" "${2:?run dir}" ;;
	down) "${COMPOSE[@]}" down ;;
	*) sed -n '2,17p' "$0"; exit 2 ;;
esac
