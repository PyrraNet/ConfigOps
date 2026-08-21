import Notice from '../components/Notice.jsx';
import { startCapture, stopCapture, useConfigOpsState } from '../data/store.js';

export default function CaptureControls() {
	const { __ } = window.wp.i18n;
	const state = useConfigOpsState();
	const [name, setName] = window.wp.element.useState('');
	const [composerOpen, setComposerOpen] = window.wp.element.useState(false);
	const busy = Boolean(state.ui.pending);
	const isNetwork = state.scope?.type === 'network';

	window.wp.element.useEffect(() => {
		const id = 'wp-admin-bar-configops-recording';
		let node = document.getElementById(id);
		if (!state.active) {
			node?.remove();
			return;
		}

		if (!node) {
			const root = document.getElementById('wp-admin-bar-root-default');
			if (!root) return;
			node = document.createElement('li');
			node.id = id;
			node.className = 'configops-toolbar-recording';
			const link = document.createElement('a');
			link.className = 'ab-item';
			link.href = window.location.href;
			const dot = document.createElement('span');
			dot.className = 'configops-recording-dot';
			dot.setAttribute('aria-hidden', 'true');
			const label = document.createElement('span');
			label.className = 'configops-recording-label';
			label.textContent = __('CONFIGOPS RECORDING', 'configops');
			const count = document.createElement('span');
			count.className = 'configops-recording-count';
			link.append(dot, label, count);
			node.append(link);
			root.append(node);
		}

		const count = node.querySelector('.configops-recording-count');
		if (count) count.textContent = String(state.active.reviewChangeCount);
	}, [state.active, __]);

	const submit = (event) => {
		event.preventDefault();
		startCapture(name.trim());
	};

	return (
		<>
			<Notice notice={state.notice} />
			{state.active ? (
				<section className="configops-capture-command is-recording" aria-labelledby="configops-recording-title">
					<div className="configops-recording-state">
						<span className="configops-pulse" aria-hidden="true"></span>
						<div>
							<p className="configops-state-label">{__('Recording now', 'configops')}</p>
							<h2 id="configops-recording-title">{state.active.name}</h2>
						</div>
					</div>
					<div className="configops-recording-tally">
						<span className="configops-recording-primary-count">
							<strong>{state.active.reviewChangeCount}</strong>
							<span>{state.active.reviewChangeCount === 1 ? __('setting', 'configops') : __('settings', 'configops')}</span>
						</span>
						{(state.active.technicalChangeCount > 0 || state.active.writeSignalCount > 0) && (
							<span className="configops-recording-secondary-count">
								{state.active.technicalChangeCount > 0 && `${state.active.technicalChangeCount} ${__('technical', 'configops')}`}
								{state.active.technicalChangeCount > 0 && state.active.writeSignalCount > 0 && ' · '}
								{state.active.writeSignalCount > 0 && `${state.active.writeSignalCount} ${__('outside API', 'configops')}`}
							</span>
						)}
					</div>
					<button className="button button-primary button-large" type="button" disabled={busy} onClick={stopCapture}>
						{state.ui.pending === 'stop-capture' ? __('Stopping…', 'configops') : __('Stop & review', 'configops')}
					</button>
				</section>
			) : !composerOpen ? (
				<section className="configops-capture-command is-compact" aria-labelledby="configops-new-capture-title">
					<div>
						<p className="configops-state-label">{isNetwork ? __('Automatic network recording is on', 'configops') : __('Automatic recording is on', 'configops')}</p>
						<h2 id="configops-new-capture-title">{isNetwork ? __('Network settings changes are recorded as they happen', 'configops') : __('Settings changes are recorded as they happen', 'configops')}</h2>
					</div>
					<button className="button" type="button" onClick={() => setComposerOpen(true)}>{isNetwork ? __('Start network session', 'configops') : __('Start change session', 'configops')}</button>
				</section>
			) : (
				<section className="configops-capture-command" aria-labelledby="configops-start-title">
					<form className="configops-capture-form" onSubmit={submit}>
						<div className="configops-capture-intro">
							<p className="configops-state-label">{__('Focused mode', 'configops')}</p>
							<h2 id="configops-start-title">{isNetwork ? __('Start a named network session', 'configops') : __('Start a named change session', 'configops')}</h2>
							<p>{__('Group a planned maintenance task, support case, or investigation under one name.', 'configops')}</p>
						</div>
						<div className="configops-capture-field">
							<label className="screen-reader-text" htmlFor="configops-capture-name">{__('Capture name', 'configops')}</label>
							<input
								id="configops-capture-name"
								name="capture_name"
								type="text"
								maxLength="191"
								placeholder={__('What are you changing?', 'configops')}
								value={name}
								onChange={(event) => setName(event.target.value)}
							/>
						</div>
						<div className="configops-capture-compose-actions">
							<button className="button" type="button" disabled={busy} onClick={() => setComposerOpen(false)}>{__('Cancel', 'configops')}</button>
							<button className="button button-primary button-large" type="submit" disabled={busy}>
								{state.ui.pending === 'start-capture' ? __('Starting…', 'configops') : __('Start session', 'configops')}
							</button>
						</div>
					</form>
				</section>
			)}
		</>
	);
}
