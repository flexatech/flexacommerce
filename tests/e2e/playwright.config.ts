import { defineConfig, devices } from '@playwright/test';

/**
 * Browser journeys on the bench store (tools/bench/bench.sh e2e-setup, then
 * bench.sh e2e). One project per theme: the e2e mu-plugin on e2e.bench
 * switches the theme from the X-FXC-E2E-Theme header, so all three run
 * against the same store and the same data.
 */
const themes = {
	tt5: 'twentytwentyfive',
	astra: 'astra',
	tt1: 'twentytwentyone',
} as const;

export default defineConfig( {
	testDir: './specs',
	// The journeys share one store and the stock in it.
	workers: 1,
	fullyParallel: false,
	retries: 0,
	reporter: [ [ 'list' ], [ 'json', { outputFile: 'results/report.json' } ] ],
	outputDir: 'results/artifacts',
	use: {
		baseURL: 'http://e2e.bench',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		launchOptions: {
			args: [ '--host-resolver-rules=MAP e2e.bench 127.0.0.1:8089' ],
		},
	},
	projects: Object.entries( themes ).map( ( [ name, theme ] ) => ( {
		name,
		use: {
			...devices[ 'Desktop Chrome' ],
			extraHTTPHeaders: { 'X-FXC-E2E-Theme': theme },
		},
	} ) ),
} );
