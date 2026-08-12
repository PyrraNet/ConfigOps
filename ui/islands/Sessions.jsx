import Hint from '../components/Hint.jsx';
import { selectSession, useConfigOpsState } from '../data/store.js';

export default function Sessions() {
	const { __, sprintf } = window.wp.i18n;
	const state = useConfigOpsState();

	return (
		<>
			<div className="configops-section-heading">
				<div>
					<h2>{__('Captures', 'configops')}</h2>
					<Hint label={__('What is a capture?', 'configops')}>
						{__('Everything ConfigOps observes between Record and Stop, kept together as one review.', 'configops')}
					</Hint>
				</div>
				<span aria-label={sprintf(__('%d captures', 'configops'), state.sessions.length)}>{state.sessions.length}</span>
			</div>
			{state.sessions.length === 0 ? (
				<p className="configops-empty-copy">{__('Your captures will appear here.', 'configops')}</p>
			) : (
				<ol className="configops-session-list">
					{state.sessions.map((session) => {
						const selected = state.selected?.id === session.id;
						return (
							<li key={session.id}>
								<a
									className={selected ? 'is-selected' : ''}
									href={`?page=configops&session=${session.id}`}
									aria-current={selected ? 'page' : undefined}
									onClick={(event) => {
										event.preventDefault();
										selectSession(session.id);
									}}
								>
									<span className="configops-session-head">
										<span className="configops-session-name">{session.name}</span>
										<code>#{session.id}</code>
									</span>
									<span className="configops-session-meta">
										<span>
											{sprintf(__('%d changes', 'configops'), session.mutationCount)}
											{session.writeSignalCount > 0 && <em>{sprintf(__(' · %d outside API', 'configops'), session.writeSignalCount)}</em>}
										</span>
										<time dateTime={session.startedAt}>{sprintf(__('%s ago', 'configops'), session.startedAtLabel)}</time>
									</span>
								</a>
							</li>
						);
					})}
				</ol>
			)}
		</>
	);
}
