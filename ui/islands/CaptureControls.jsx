import Notice from '../components/Notice.jsx';
import Hint from '../components/Hint.jsx';
import { startCapture, stopCapture, useConfigOpsState } from '../data/store.js';

export default function CaptureControls() {
	const { __ } = window.wp.i18n;
	const state = useConfigOpsState();
	const [name, setName] = window.wp.element.useState('');
	const busy = Boolean(state.ui.pending);

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
							<p className="configops-state-label">{__('Recording', 'configops')}</p>
							<h2 id="configops-recording-title">{state.active.name}</h2>
						</div>
					</div>
					<div className="configops-recording-tally">
						<strong>{state.active.mutationCount}</strong>
						<span>{__('changes found', 'configops')}</span>
						{state.active.writeSignalCount > 0 && (
							<span className="configops-recording-writes">{`+ ${state.active.writeSignalCount} ${__('outside the settings API', 'configops')}`}</span>
						)}
						<Hint label={__('What counts as a change?', 'configops')} align="end">
							{__('ConfigOps counts settings saved through WordPress. Database writes outside that standard route are listed separately without storing their query or values.', 'configops')}
						</Hint>
					</div>
					<button className="button button-primary button-large" type="button" disabled={busy} onClick={stopCapture}>
						{state.ui.pending === 'stop-capture' ? __('Stopping…', 'configops') : __('Stop & review', 'configops')}
					</button>
				</section>
			) : (
				<section className="configops-capture-command" aria-labelledby="configops-start-title">
					<h2 id="configops-start-title" className="screen-reader-text">{__('Start a capture', 'configops')}</h2>
					<form className="configops-capture-form" onSubmit={submit}>
						<div className="configops-capture-field">
							<div className="configops-field-label">
								<label htmlFor="configops-capture-name">{__('Capture name', 'configops')}</label>
								<Hint label={__('Why name a capture?', 'configops')}>
									{__('Name the task you are about to do. ConfigOps will keep everything until Stop together in one review.', 'configops')}
								</Hint>
							</div>
							<input
								id="configops-capture-name"
								name="capture_name"
								type="text"
								maxLength="191"
								placeholder={__('SMTP production baseline', 'configops')}
								value={name}
								onChange={(event) => setName(event.target.value)}
							/>
						</div>
						<button className="button button-primary button-large" type="submit" disabled={busy}>
							{state.ui.pending === 'start-capture' ? __('Starting…', 'configops') : __('Record changes', 'configops')}
						</button>
					</form>
				</section>
			)}
		</>
	);
}
