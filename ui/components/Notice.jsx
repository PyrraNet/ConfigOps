import { dismissNotice } from '../data/store.js';

export default function Notice({ notice }) {
	const { __ } = window.wp.i18n;
	if (!notice?.text) {
		return null;
	}

	return (
		<div className={`notice notice-${notice.kind === 'error' ? 'error' : 'success'} is-dismissible configops-notice`} role={notice.kind === 'error' ? 'alert' : 'status'}>
			<p>{notice.text}</p>
			<button type="button" className="notice-dismiss" onClick={dismissNotice}>
				<span className="screen-reader-text">{__('Dismiss this notice.', 'configops')}</span>
			</button>
		</div>
	);
}
