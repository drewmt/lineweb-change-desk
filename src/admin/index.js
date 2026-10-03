import apiFetch from '@wordpress/api-fetch';
import { createRoot, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import './style.scss';

const provider = window.lwcdAdmin.provider;
apiFetch.use( apiFetch.createNonceMiddleware( window.lwcdAdmin.nonce ) );
const call = ( path, method = 'GET', data ) =>
	apiFetch( { path: `/lineweb-change-desk/v1/${ path }`, method, data } );
const labels = {
	applied: __( 'Applied', 'lineweb-change-desk' ),
	restored: __( 'Restored', 'lineweb-change-desk' ),
	conflict: __( 'Conflict: source changed or locked', 'lineweb-change-desk' ),
	failed: __( 'Not applied', 'lineweb-change-desk' ),
	needs_verification: __(
		'Needs verification. Do not repeat the write.',
		'lineweb-change-desk'
	),
};
const time = ( stamp ) =>
	new Date( stamp * 1000 ).toLocaleString(
		document.documentElement.lang || 'en'
	);
const recordedOutcome = ( data ) => {
	const sources = Object.assign(
		{},
		...Object.values( data.operations ).map( ( op ) => op.sources )
	);
	return Object.keys( sources ).length ? { sources } : null;
};

function Desk() {
	const [ mode, setMode ] = useState( 'exact' );
	const [ instruction, setInstruction ] = useState( '' );
	const [ find, setFind ] = useState( '' );
	const [ replacement, setReplacement ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ listing, setListing ] = useState( {
		items: [],
		page: 1,
		pages: 1,
	} );
	const [ selected, setSelected ] = useState( [] );
	const [ preview, setPreview ] = useState( null );
	const [ job, setJob ] = useState( null );
	const [ approved, setApproved ] = useState( [] );
	const [ confirm, setConfirm ] = useState( false );
	const [ consent, setConsent ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ history, setHistory ] = useState( {
		items: [],
		page: 1,
		has_more: false,
	} );
	const [ outcome, setOutcome ] = useState( null );
	const stopped = useRef( false );
	const reviewHeading = useRef( null );
	const operation = useRef( null );
	const refreshHistory = async ( page = 1 ) =>
		setHistory( await call( `jobs?page=${ page }` ) );
	async function action( work ) {
		setBusy( true );
		setError( '' );
		try {
			await work();
		} catch ( e ) {
			setError(
				e.message ||
					__(
						'The request could not be verified. Refresh job status before trying again.',
						'lineweb-change-desk'
					)
			);
		} finally {
			setBusy( false );
		}
	}
	async function loadSources( page = 1 ) {
		setListing(
			await call(
				`sources?search=${ encodeURIComponent(
					search
				) }&page=${ page }`
			)
		);
	}
	useEffect( () => {
		action( async () => {
			setListing( await call( 'sources?search=&page=1' ) );
			await refreshHistory();
		} );
	}, [] );
	useEffect( () => {
		if ( confirm ) {
			reviewHeading.current?.focus();
		}
	}, [ confirm ] );
	function invalidate() {
		setPreview( null );
		setConsent( false );
		setConfirm( false );
	}
	function reconcile( data ) {
		const pending = operation.current;
		if ( ! pending ) {
			return;
		}
		const known = data.operations[ pending.id ];
		if (
			pending.jobId !== data.id ||
			( known &&
				pending.sources.every(
					( id ) =>
						known.sources[ id ] &&
						known.sources[ id ].state !== 'needs_verification'
				) )
		) {
			operation.current = null;
		}
	}
	async function loadJob( id ) {
		const data = await call( `jobs/${ id }` );
		setJob( data );
		setApproved( [] );
		setConfirm( false );
		setOutcome( recordedOutcome( data ) );
		reconcile( data );
	}
	async function prepare() {
		const hashes = Object.fromEntries(
			Object.values( preview.sources ).map( ( s ) => [ s.id, s.hash ] )
		);
		const data = await call( 'jobs', 'POST', {
			mode,
			instruction,
			find,
			replacement,
			source_ids: preview.ids,
			source_hashes: hashes,
		} );
		setJob( data );
		setApproved( [] );
		setOutcome( null );
		setConfirm( false );
		operation.current = null;
		await refreshHistory();
	}
	async function analyze() {
		stopped.current = false;
		let current = job;
		for ( let i = 0; i < 5 && ! stopped.current; i++ ) {
			current = await call( `jobs/${ current.id }/analyze`, 'POST', {
				confirmed_provider_transfer: true,
			} );
			setJob( current );
			if ( current.status !== 'pending' ) {
				break;
			}
		}
		await refreshHistory();
	}
	async function write( restore = false ) {
		if ( operation.current ) {
			return;
		}
		const path = restore ? 'restore' : 'apply';
		const ids = restore
			? Object.keys( job.snapshots )
					.filter( ( id ) => ! job.snapshots[ id ].restored )
					.map( Number )
			: approved;
		operation.current = {
			id: crypto.randomUUID(),
			path,
			jobId: job.id,
			sources: restore
				? ids
				: [
						...new Set(
							job.proposals
								.filter( ( proposal ) =>
									ids.includes( proposal.id )
								)
								.map( ( proposal ) => proposal.post_id )
						),
				  ],
		};
		try {
			const result = await call( `jobs/${ job.id }/${ path }`, 'POST', {
				operation_id: operation.current.id,
				[ restore ? 'post_ids' : 'proposal_ids' ]: ids,
			} );
			setOutcome( result );
			const current = await call( `jobs/${ job.id }` );
			setJob( current );
			reconcile( current );
			setApproved( [] );
			setConfirm( false );
			await refreshHistory();
		} catch ( e ) {
			// Reconcile by reading stored outcomes. Never automatically resend a write.
			try {
				const current = await call( `jobs/${ job.id }` );
				setJob( current );
				const known = current.operations[ operation.current?.id ];
				if ( known ) {
					setOutcome( { sources: known.sources } );
				}
				reconcile( current );
				if ( ! operation.current ) {
					setApproved( [] );
					setConfirm( false );
				}
			} catch {
				/* Keep the original actionable error. */
			}
			throw e;
		}
	}
	const canRestore =
		job && Object.values( job.snapshots ).some( ( s ) => ! s.restored );
	return (
		<>
			<div
				className="lwcd-principles"
				aria-label={ __(
					'Workflow safeguards',
					'lineweb-change-desk'
				) }
			>
				<span>
					{ __(
						'Selected posts and pages only',
						'lineweb-change-desk'
					) }
				</span>
				<span>
					{ __(
						'Nothing changes without your approval',
						'lineweb-change-desk'
					) }
				</span>
				<span>
					{ __(
						'Private history for 30 days',
						'lineweb-change-desk'
					) }
				</span>
			</div>
			{ error && (
				<div className="lwcd-alert" role="alert">
					{ error }
				</div>
			) }
			<section
				id="lwcd-workspace"
				className="lwcd-panel"
				aria-busy={ busy }
			>
				<div className="lwcd-heading">
					<span className="lwcd-step">01</span>
					<div>
						<h2>
							{ __( 'What changed?', 'lineweb-change-desk' ) }
						</h2>
						<p>
							{ __(
								'Use an exact change without AI, or describe a factual update for AI proposals.',
								'lineweb-change-desk'
							) }
						</p>
					</div>
				</div>
				<fieldset className="lwcd-modes" disabled={ busy }>
					<legend className="screen-reader-text">
						{ __( 'Change mode', 'lineweb-change-desk' ) }
					</legend>
					<label htmlFor="lwcd-mode-exact">
						<input
							id="lwcd-mode-exact"
							type="radio"
							name="lwcd-mode"
							checked={ mode === 'exact' }
							onChange={ () => {
								setMode( 'exact' );
								invalidate();
							} }
						/>
						{ __(
							'Exact text change (no AI)',
							'lineweb-change-desk'
						) }
					</label>
					<label htmlFor="lwcd-mode-ai">
						<input
							id="lwcd-mode-ai"
							type="radio"
							name="lwcd-mode"
							checked={ mode === 'ai' }
							disabled={ ! provider.available }
							onChange={ () => {
								setMode( 'ai' );
								invalidate();
							} }
						/>
						{ __( 'AI proposals', 'lineweb-change-desk' ) }
					</label>
				</fieldset>
				<p className="lwcd-provider">
					{ provider.message }{ ' ' }
					{ ! provider.available && (
						<a href={ provider.settings_url }>
							{ __(
								'Connector settings',
								'lineweb-change-desk'
							) }
						</a>
					) }
				</p>
				{ mode === 'exact' ? (
					<div className="lwcd-form-grid">
						<label htmlFor="lwcd-find">
							{ __( 'Old text', 'lineweb-change-desk' ) }
							<input
								id="lwcd-find"
								value={ find }
								maxLength={ 2000 }
								disabled={ busy }
								onChange={ ( e ) => {
									setFind( e.target.value );
									invalidate();
								} }
								placeholder="09:00–17:00"
							/>
						</label>
						<label htmlFor="lwcd-replacement">
							{ __( 'New text', 'lineweb-change-desk' ) }
							<input
								id="lwcd-replacement"
								value={ replacement }
								maxLength={ 2000 }
								disabled={ busy }
								onChange={ ( e ) => {
									setReplacement( e.target.value );
									invalidate();
								} }
								placeholder="10:00–18:00"
							/>
						</label>
					</div>
				) : (
					<label className="lwcd-label" htmlFor="lwcd-instruction">
						{ __(
							'Describe the factual change',
							'lineweb-change-desk'
						) }
						<textarea
							id="lwcd-instruction"
							rows={ 4 }
							value={ instruction }
							maxLength={ 2000 }
							disabled={ busy }
							onChange={ ( e ) => {
								setInstruction( e.target.value );
								invalidate();
							} }
						/>
						<small>
							{ sprintf(
								// translators: %d: displayed count.
								__(
									'%d / 2,000 characters',
									'lineweb-change-desk'
								),
								Array.from( instruction ).length
							) }
						</small>
					</label>
				) }
				<div className="lwcd-heading">
					<span className="lwcd-step">02</span>
					<div>
						<h2>
							{ __(
								'Choose the content',
								'lineweb-change-desk'
							) }
						</h2>
						<p>
							{ __(
								'Select up to ten published posts or pages you can edit. Products, builders and templates are outside this version.',
								'lineweb-change-desk'
							) }
						</p>
					</div>
				</div>
				<form
					className="lwcd-search"
					onSubmit={ ( e ) => {
						e.preventDefault();
						action( () => loadSources() );
					} }
				>
					<label htmlFor="lwcd-search">
						{ __( 'Search content', 'lineweb-change-desk' ) }
						<input
							id="lwcd-search"
							value={ search }
							maxLength={ 200 }
							disabled={ busy }
							onChange={ ( e ) => setSearch( e.target.value ) }
						/>
					</label>
					<button disabled={ busy }>
						{ __( 'Search', 'lineweb-change-desk' ) }
					</button>
				</form>
				<div className="lwcd-source-list">
					{ listing.items.map( ( s ) => (
						<label
							key={ s.id }
							className="lwcd-source"
							htmlFor={ `lwcd-source-${ s.id }` }
						>
							<input
								id={ `lwcd-source-${ s.id }` }
								aria-label={ sprintf(
									// translators: %s: displayed source title.
									__( 'Select %s', 'lineweb-change-desk' ),
									s.title
								) }
								type="checkbox"
								checked={ selected.includes( s.id ) }
								disabled={
									busy ||
									( ! selected.includes( s.id ) &&
										selected.length >= 10 )
								}
								onChange={ () => {
									setSelected(
										selected.includes( s.id )
											? selected.filter(
													( id ) => id !== s.id
											  )
											: [ ...selected, s.id ]
									);
									invalidate();
								} }
							/>
							<span>
								<strong>
									{ s.title ||
										__(
											'Untitled',
											'lineweb-change-desk'
										) }
								</strong>
								<small>
									{ s.type === 'page'
										? __( 'Page', 'lineweb-change-desk' )
										: __( 'Post', 'lineweb-change-desk' ) }
								</small>
							</span>
						</label>
					) ) }
					{ ! listing.items.length && (
						<p>
							{ __(
								'No editable published content matched this search.',
								'lineweb-change-desk'
							) }
						</p>
					) }
				</div>
				<div className="lwcd-actions">
					<button
						disabled={ busy || listing.page <= 1 }
						onClick={ () =>
							action( () => loadSources( listing.page - 1 ) )
						}
					>
						{ __( 'Previous', 'lineweb-change-desk' ) }
					</button>
					<span>
						{ sprintf(
							// translators: 1: displayed count; 2: displayed count.
							__(
								'%1$d selected · page %2$d',
								'lineweb-change-desk'
							),
							selected.length,
							listing.page
						) }
					</span>
					<button
						disabled={ busy || listing.page >= listing.pages }
						onClick={ () =>
							action( () => loadSources( listing.page + 1 ) )
						}
					>
						{ __( 'Next', 'lineweb-change-desk' ) }
					</button>
				</div>
				<button
					className="lwcd-primary"
					disabled={
						busy ||
						! selected.length ||
						( mode === 'exact'
							? ! find || find === replacement
							: ! instruction.trim() )
					}
					onClick={ () =>
						action( async () => {
							setPreview(
								await call( 'preview', 'POST', {
									source_ids: selected,
								} )
							);
							setJob( null );
							setOutcome( null );
							setApproved( [] );
							setConsent( false );
						} )
					}
				>
					{ busy
						? __( 'Working…', 'lineweb-change-desk' )
						: __(
								'Preview selected content',
								'lineweb-change-desk'
						  ) }
				</button>
			</section>
			{ preview && ! job && (
				<section className="lwcd-panel">
					<div className="lwcd-heading">
						<span className="lwcd-step">03</span>
						<div>
							<h2>
								{ __(
									'Check the selected excerpts',
									'lineweb-change-desk'
								) }
							</h2>
							<p>
								{ __(
									'These plain-text fields are the entire analysis scope. Phrases split across inline formatting need manual review.',
									'lineweb-change-desk'
								) }
							</p>
						</div>
					</div>
					{ Object.values( preview.sources ).map( ( s ) => (
						<details key={ s.id } className="lwcd-excerpts" open>
							<summary>
								{ s.title } · { s.fields.length }{ ' ' }
								{ __( 'text fields', 'lineweb-change-desk' ) }
							</summary>
							<div>
								{ s.fields.map( ( f ) => (
									<p key={ f.id }>{ f.original }</p>
								) ) }
							</div>
							{ s.unsupported.length > 0 && (
								<p className="lwcd-note">
									{ sprintf(
										// translators: %d: displayed count.
										__(
											'%d unsupported areas require manual review.',
											'lineweb-change-desk'
										),
										s.unsupported.length
									) }
								</p>
							) }
						</details>
					) ) }
					{ mode === 'ai' && (
						<label className="lwcd-consent" htmlFor="lwcd-consent">
							<input
								id="lwcd-consent"
								type="checkbox"
								checked={ consent }
								onChange={ ( e ) =>
									setConsent( e.target.checked )
								}
							/>
							{ sprintf(
								// translators: %s: displayed count.
								__(
									'Send only these excerpts and my instruction to %s. Provider charges and retention terms may apply.',
									'lineweb-change-desk'
								),
								provider.provider_id
							) }
						</label>
					) }
					<button
						className="lwcd-primary"
						disabled={ busy || ( mode === 'ai' && ! consent ) }
						onClick={ () => action( prepare ) }
					>
						{ mode === 'exact'
							? __(
									'Prepare exact proposals',
									'lineweb-change-desk'
							  )
							: __(
									'Create reviewed AI job',
									'lineweb-change-desk'
							  ) }
					</button>
				</section>
			) }
			{ job && (
				<section className="lwcd-panel">
					<div className="lwcd-heading">
						<span className="lwcd-step">04</span>
						<div>
							<h2>
								{ __(
									'Review each proposed change',
									'lineweb-change-desk'
								) }
							</h2>
							<p>
								{ __(
									'No proposals are selected automatically. Check facts and context before applying.',
									'lineweb-change-desk'
								) }
							</p>
						</div>
					</div>
					<div className="lwcd-coverage">
						<span>
							{ sprintf(
								// translators: %d: displayed count.
								__(
									'%d supported fields',
									'lineweb-change-desk'
								),
								job.coverage.supported
							) }
						</span>
						<span>
							{ sprintf(
								// translators: %d: displayed count.
								__( '%d analyzed', 'lineweb-change-desk' ),
								job.coverage.analyzed
							) }
						</span>
						<span>
							{ sprintf(
								// translators: %d: displayed count.
								__( '%d failed', 'lineweb-change-desk' ),
								job.coverage.failed
							) }
						</span>
						<span>
							{ sprintf(
								// translators: %d: displayed count.
								__( '%d unscanned', 'lineweb-change-desk' ),
								job.coverage.unscanned
							) }
						</span>
						<span>
							{ sprintf(
								// translators: %d: displayed count.
								__( '%d manual areas', 'lineweb-change-desk' ),
								job.coverage.manual
							) }
						</span>
					</div>
					{ job.mode === 'ai' && (
						<div className="lwcd-note">
							<p>
								{ sprintf(
									// translators: 1: displayed provider, request reservation count or job status; 2: displayed provider, request reservation count or job status; 3: displayed provider, request reservation count or job status.
									__(
										'Provider: %1$s · %2$d requests reserved · job status: %3$s',
										'lineweb-change-desk'
									),
									job.provider_id,
									job.requests_used,
									job.status
								) }
							</p>
							{ job.last_error && <p>{ job.last_error }</p> }
							{ [ 'pending', 'partial' ].includes(
								job.status
							) && (
								<button
									disabled={ busy }
									onClick={ () => action( analyze ) }
								>
									{ __(
										'Start or continue confirmed AI analysis',
										'lineweb-change-desk'
									) }
								</button>
							) }
							{ [ 'pending', 'analyzing', 'partial' ].includes(
								job.status
							) && (
								<button
									onClick={ () => {
										stopped.current = true;
										call(
											`jobs/${ job.id }/cancel`,
											'POST'
										)
											.then( setJob )
											.catch( ( e ) =>
												setError( e.message )
											);
									} }
								>
									{ __(
										'Cancel further analysis',
										'lineweb-change-desk'
									) }
								</button>
							) }
						</div>
					) }
					{ ! job.proposals.length && (
						<p className="lwcd-empty">
							{ __(
								'No applicable proposals. This is not a complete-site scan. Check unsupported areas, split phrases and the analysis status.',
								'lineweb-change-desk'
							) }
						</p>
					) }
					{ job.proposals.map( ( p, i ) => (
						<article className="lwcd-proposal" key={ p.id }>
							<label
								className="lwcd-approve"
								htmlFor={ `lwcd-approve-${ p.id }` }
							>
								<input
									id={ `lwcd-approve-${ p.id }` }
									aria-label={ sprintf(
										// translators: %d: displayed count.
										__(
											'Approve change %d',
											'lineweb-change-desk'
										),
										i + 1
									) }
									type="checkbox"
									checked={ approved.includes( p.id ) }
									disabled={
										busy ||
										confirm ||
										!! operation.current ||
										!! job.snapshots[ p.post_id ]
									}
									onChange={ () =>
										setApproved(
											approved.includes( p.id )
												? approved.filter(
														( id ) => id !== p.id
												  )
												: [ ...approved, p.id ]
										)
									}
								/>
								<strong>
									{ job.sources[ p.post_id ]?.title }
								</strong>
							</label>
							<div className="lwcd-diff">
								<div>
									<small>
										{ __(
											'Before',
											'lineweb-change-desk'
										) }
									</small>
									<p>{ p.original }</p>
								</div>
								<div>
									<small>
										{ __(
											'Proposed',
											'lineweb-change-desk'
										) }
									</small>
									<p>
										{ p.replacement ||
											__(
												'(Remove this text)',
												'lineweb-change-desk'
											) }
									</p>
								</div>
							</div>
							<p className="lwcd-reason">{ p.reason }</p>
						</article>
					) ) }
					<div className="lwcd-actions">
						<button
							className="lwcd-primary"
							disabled={
								busy ||
								! approved.length ||
								confirm ||
								!! operation.current
							}
							onClick={ () => setConfirm( true ) }
						>
							{ __(
								'Review selected changes',
								'lineweb-change-desk'
							) }
						</button>
						<button
							disabled={ busy }
							onClick={ () => action( () => loadJob( job.id ) ) }
						>
							{ __(
								'Refresh job status',
								'lineweb-change-desk'
							) }
						</button>
					</div>
					{ confirm && (
						<section className="lwcd-confirm">
							<h3 ref={ reviewHeading } tabIndex={ -1 }>
								{ __( 'Final review', 'lineweb-change-desk' ) }
							</h3>
							<p>
								{ sprintf(
									// translators: %d: displayed count.
									__(
										'Apply %d selected text changes. Source content and permissions will be checked again. Third-party emails or webhooks cannot be undone by restoring text.',
										'lineweb-change-desk'
									),
									approved.length
								) }
							</p>
							<div className="lwcd-actions">
								<button
									className="lwcd-primary"
									disabled={ busy || !! operation.current }
									onClick={ () => action( () => write() ) }
								>
									{ __(
										'Apply approved changes',
										'lineweb-change-desk'
									) }
								</button>
								<button
									disabled={ busy }
									onClick={ () => setConfirm( false ) }
								>
									{ __(
										'Back to review',
										'lineweb-change-desk'
									) }
								</button>
							</div>
						</section>
					) }
					{ outcome && (
						<div className="lwcd-outcomes" role="status">
							{ Object.entries( outcome.sources ).map(
								( [ id, result ] ) => (
									<p key={ id }>
										<strong>
											{ job.sources[ id ]?.title }:
										</strong>{ ' ' }
										<span>
											{ labels[ result.state ] ||
												result.state }
										</span>
									</p>
								)
							) }
						</div>
					) }
					{ Object.entries( job.operations ).map( ( [ id, op ] ) => (
						<div className="lwcd-operation" key={ id }>
							<strong>
								{ op.type === 'restore'
									? __( 'Restore', 'lineweb-change-desk' )
									: __( 'Apply', 'lineweb-change-desk' ) }
							</strong>
							{ ' · ' }
							{ sprintf(
								/* translators: %d: WordPress user ID. */ __(
									'User %d',
									'lineweb-change-desk'
								),
								op.actor_id || job.owner
							) }
							{ op.created ? ` · ${ time( op.created ) }` : '' }
							{ Object.entries( op.sources ).map(
								( [ source, result ] ) => (
									<p key={ source }>
										{ job.sources[ source ]?.title }:{ ' ' }
										{ labels[ result.state ] ||
											result.state }
									</p>
								)
							) }
						</div>
					) ) }
					{ canRestore && (
						<div className="lwcd-restore">
							<p>
								{ __(
									'Restore is available only while the saved after-version is still current. Newer human edits are never force-overwritten.',
									'lineweb-change-desk'
								) }
							</p>
							<button
								disabled={ busy || !! operation.current }
								onClick={ () => action( () => write( true ) ) }
							>
								{ __(
									'Restore original content',
									'lineweb-change-desk'
								) }
							</button>
						</div>
					) }
					<p className="lwcd-note">
						{ sprintf(
							// translators: %s: displayed expiry date.
							__(
								'Private job expires: %s. Deleting a source removes this entire job and its restore history.',
								'lineweb-change-desk'
							),
							time( job.expires )
						) }
					</p>
				</section>
			) }
			<section id="lwcd-history" className="lwcd-panel">
				<h2>
					{ __(
						'Your recent change history',
						'lineweb-change-desk'
					) }
				</h2>
				<p>
					{ __(
						'Only jobs whose source content you can still edit are shown. Excerpts and restore snapshots expire after 30 days.',
						'lineweb-change-desk'
					) }
				</p>
				{ ! history.items.length && (
					<p className="lwcd-empty">
						{ __(
							'No reviewed changes yet. Start with a small, real update.',
							'lineweb-change-desk'
						) }
					</p>
				) }
				{ history.items.map( ( h ) => (
					<div className="lwcd-history-row" key={ h.id }>
						<span>
							<strong>
								{ h.mode === 'exact'
									? __(
											'Exact change',
											'lineweb-change-desk'
									  )
									: __(
											'AI proposals',
											'lineweb-change-desk'
									  ) }
							</strong>
							<small>
								{ time( h.created ) } · { h.sources }{ ' ' }
								{ __( 'sources', 'lineweb-change-desk' ) }
							</small>
						</span>
						<div className="lwcd-actions">
							<button
								disabled={ busy }
								onClick={ () =>
									action( () => loadJob( h.id ) )
								}
							>
								{ __( 'Open job', 'lineweb-change-desk' ) }
							</button>
							<button
								disabled={ busy }
								onClick={ () =>
									action( async () => {
										await call(
											`jobs/${ h.id }`,
											'DELETE'
										);
										if ( job?.id === h.id ) {
											setJob( null );
											setOutcome( null );
										}
										await refreshHistory();
									} )
								}
							>
								{ __(
									'Delete private history',
									'lineweb-change-desk'
								) }
							</button>
						</div>
					</div>
				) ) }
				<div className="lwcd-actions">
					<button
						disabled={ busy || history.page <= 1 }
						onClick={ () =>
							action( () => refreshHistory( history.page - 1 ) )
						}
					>
						{ __( 'Previous history', 'lineweb-change-desk' ) }
					</button>
					<button
						disabled={ busy || ! history.has_more }
						onClick={ () =>
							action( () => refreshHistory( history.page + 1 ) )
						}
					>
						{ __( 'Next history', 'lineweb-change-desk' ) }
					</button>
				</div>
			</section>
		</>
	);
}
createRoot( document.getElementById( 'lwcd-root' ) ).render( <Desk /> );
