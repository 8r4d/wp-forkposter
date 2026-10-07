/**
 * Browser test for the block editor's Versions panel and the Version history block.
 * Run through tests/run.sh --editor, which starts WordPress Playground and sets BASE_URL.
 * Needs Playwright: npm install && npx playwright install chromium
 */
import { chromium } from 'playwright';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:9480';
let failures = 0;

function check( label, condition, detail = '' ) {
	console.log( `${ condition ? 'PASS' : 'FAIL' } ${ label }${ ! condition && detail ? ` (${ detail })` : '' }` );
	if ( ! condition ) failures++;
}

const browser = await chromium.launch();
const page = await browser.newPage( { viewport: { width: 1440, height: 1000 } } );
const pluginErrors = [];
page.on( 'pageerror', ( error ) => pluginErrors.push( error.message ) );
page.on( 'console', ( message ) => {
	if ( message.type() === 'error' && /forkposter/i.test( message.text() + JSON.stringify( message.location() ) ) ) {
		pluginErrors.push( message.text() );
	}
} );

async function json( path ) {
	return JSON.parse( await ( await page.goto( BASE + path ) ).text() );
}

async function openEditor( postId ) {
	await page.goto( `${ BASE }/wp-admin/post.php?post=${ postId }&action=edit`, { waitUntil: 'load' } );
	await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId() );
	// The welcome guide is a modal that hides the rest of the editor from Playwright.
	const close = page.locator( '.components-modal__frame button[aria-label="Close"]' );
	try {
		await close.waitFor( { timeout: 4000 } );
		await close.click();
	} catch {}
	await page.evaluate( () => wp.data.dispatch( 'core/edit-post' ).openGeneralSidebar( 'edit-post/document' ) );
	const toggle = page.getByRole( 'button', { name: 'Versions', exact: true } );
	await toggle.waitFor();
	if ( ( await toggle.getAttribute( 'aria-expanded' ) ) === 'false' ) await toggle.click();
	return page.locator( '.forkposter-panel' );
}

function historyBlock() {
	return page.frameLocator( 'iframe[name="editor-canvas"]' ).locator( '.wp-block-forkposter-history' ).first();
}

await page.goto( `${ BASE }/wp-admin/` ); // Playground logs in on the first request.
await json( '/forkposter-editor-fixture.php' ); // Activates the plugin if needed.
const { original, fork, originalUrl } = await json( '/forkposter-editor-fixture.php?setup' );

// The fork, still a draft.
let panel = await openEditor( fork );
check( 'classic meta box is not loaded in the block editor', ( await page.locator( '#forkposter.postbox' ).count() ) === 0 );
const forkText = await panel.innerText();
check( 'panel shows where the fork came from', forkText.includes( 'Forked from' ) && forkText.includes( 'Remote work (v.1)' ), forkText );
check( 'panel explains what publishing will do', forkText.includes( 'When you publish this' ) );

await page.getByLabel( 'Version', { exact: true } ).fill( '2.5' );
await page.getByLabel( 'Why you revisited it' ).fill( 'Replies changed my mind.' );
check( 'version preview updates while typing', ( await panel.innerText() ).includes( 'Shown as “v.2.5”' ) );
await page.getByRole( 'button', { name: 'Save draft' } ).click();
await page.waitForFunction(
	() => ! wp.data.select( 'core/editor' ).isSavingPost() && ! wp.data.select( 'core/editor' ).isEditedPostDirty(),
	null,
	{ timeout: 20000 }
);
const saved = await json( `/forkposter-editor-fixture.php?inspect=${ fork }` );
check( 'version saved', saved.version === '2.5', JSON.stringify( saved ) );
check( 'note saved', saved.note === 'Replies changed my mind.', JSON.stringify( saved ) );
check( 'version and note copied into the revision', saved.revisionVersion === '2.5' && saved.revisionNote === 'Replies changed my mind.', JSON.stringify( saved ) );

// The original: the history block shows its placeholder until another version is published.
panel = await openEditor( original );
check( 'original offers Fork this post', ( await panel.innerText() ).includes( 'Fork this post' ) );
await historyBlock().waitFor();
check( 'history block shows a placeholder with no other published version', ( await historyBlock().innerText() ).includes( 'It appears once this post has' ) );

// Publish the fork from its editor, then the original's block lists both versions.
await openEditor( fork );
await page.evaluate( async () => {
	wp.data.dispatch( 'core/editor' ).editPost( { status: 'publish' } );
	await wp.data.dispatch( 'core/editor' ).savePost();
} );
check( 'fork published', ( await json( `/forkposter-editor-fixture.php?inspect=${ fork }` ) ).status === 'publish' );

await openEditor( original );
await historyBlock().getByText( 'Versions of this piece' ).waitFor( { timeout: 15000 } );
const blockText = await historyBlock().innerText();
check( 'history block lists both versions in the editor', blockText.includes( 'Remote work (v.1)' ) && blockText.includes( 'Remote work, revisited (v.2.5)' ), blockText );

const front = await ( await page.goto( originalUrl ) ).text();
check( 'history block renders on the site', /<nav[^>]*wp-block-forkposter-history[\s\S]*Remote work, revisited \(v\.2\.5\)/.test( front ) );
check( 'no JavaScript errors from the plugin', pluginErrors.length === 0, pluginErrors.join( '; ' ) );

await browser.close();
console.log( `\n${ failures ? `${ failures } FAILED` : 'ALL PASSED' }` );
process.exit( failures ? 1 : 0 );
