import { setGenericArrayUndo, useConfigOpsState } from '../data/store.js';
import Notice from '../components/Notice.jsx';

const levelLabel = (level, __) => {
	switch (level) {
		case 'full': return __('Supported', 'configops');
		case 'partial': return __('With limits', 'configops');
		default: return __('Unavailable', 'configops');
	}
};

const PluginState = ({ adapter }) => {
	const { __ } = window.wp.i18n;
	if (!adapter.installed) {
		return <span className="configops-plugin-state is-absent">{__('Not installed', 'configops')}</span>;
	}
	if (!adapter.compatible) {
		return <span className="configops-plugin-state is-warning">{__('Untested version', 'configops')}</span>;
	}
	if (!adapter.active) {
		return <span className="configops-plugin-state is-inactive">{__('Inactive', 'configops')}</span>;
	}

	return <span className="configops-plugin-state is-ready">{__('Active', 'configops')}</span>;
};

const Capability = ({ capability, versionUntested }) => {
	const { __ } = window.wp.i18n;
	const level = versionUntested ? 'partial' : capability.level;
	const label = versionUntested ? __('Not tested on this version', 'configops') : levelLabel(level, __);

	return (
		<div className={`configops-support-capability is-${level}`}>
			<span>{capability.label}</span>
			<strong>{label}</strong>
		</div>
	);
};

const SupportSummary = ({ capabilities, versionUntested }) => {
	const { __ } = window.wp.i18n;
	if (versionUntested) {
		return <span className="configops-support-summary is-warning">{__('Automatic undo off', 'configops')}</span>;
	}

	const supported = capabilities.filter((capability) => capability.level === 'full').length;
	const limited = capabilities.filter((capability) => capability.level === 'partial').length;

	return (
		<div className="configops-support-summary" aria-label={__('Support summary', 'configops')}>
			{supported > 0 && <span className="is-full"><strong>{supported}</strong> {__('supported', 'configops')}</span>}
			{limited > 0 && <span className="is-partial"><strong>{limited}</strong> {__('limited', 'configops')}</span>}
		</div>
	);
};

const AdapterRow = ({ adapter }) => {
	const { __, sprintf } = window.wp.i18n;
	const versionUntested = adapter.installed && !adapter.compatible;
	const capabilities = adapter.capabilities.filter((capability) => capability.level !== 'planned');
	const versionLabel = adapter.version
		? sprintf(__('Installed %1$s · Tested %2$s', 'configops'), adapter.version, adapter.testedVersion)
		: sprintf(__('Tested %s', 'configops'), adapter.testedVersion);

	return (
		<details className="configops-support-row">
			<summary>
				<div className="configops-support-plugin">
					<div>
						<h3>{adapter.name}</h3>
						<p>{versionLabel}</p>
					</div>
				</div>
				<PluginState adapter={adapter} />
				<SupportSummary capabilities={capabilities} versionUntested={versionUntested} />
				<span className="configops-chevron" aria-hidden="true"></span>
			</summary>
			<div className="configops-support-detail">
				<section>
					<h4>{__('Capability checks', 'configops')}</h4>
					<div className="configops-support-capabilities">
						{capabilities.map((capability) => <Capability capability={capability} versionUntested={versionUntested} key={capability.id} />)}
					</div>
				</section>
				<div className="configops-support-notes">
					<section>
						<h4>{__('Contract includes', 'configops')}</h4>
						<ul>{adapter.coverage.map((item) => <li key={item}>{item}</li>)}</ul>
					</section>
					<section>
						<h4>{__('Refused or unmapped', 'configops')}</h4>
						<ul>{adapter.limitations.map((item) => <li key={item}>{item}</li>)}</ul>
					</section>
				</div>
			</div>
		</details>
	);
};

export default function SupportMatrix() {
	const { __ } = window.wp.i18n;
	const state = useConfigOpsState();
	const adapters = state.adapters || [];
	const genericArrayUndo = state.experiments?.genericArrayUndo;
	const experimentBusy = state.ui.pending === 'generic-array-undo';

	return (
		<div className="configops-support-ledger">
			<Notice notice={state.notice} />
			{genericArrayUndo && (
				<section className="configops-write-signal configops-experiment-control">
					<header>
						<span className="configops-sql-mark" aria-hidden="true">β</span>
						<div className="configops-write-identity">
							<strong>{__('Verified key undo for plugin arrays', 'configops')}</strong>
							<span className={`configops-experiment-state configops-plugin-state ${genericArrayUndo.enabled ? 'is-ready' : 'is-inactive'}`} role="status">
								{genericArrayUndo.enabled ? __('Experimental · enabled', 'configops') : __('Experimental · off', 'configops')}
							</span>
						</div>
						<button
							className={`button ${genericArrayUndo.enabled ? '' : 'button-primary'}`}
							type="button"
							aria-pressed={genericArrayUndo.enabled}
							disabled={!genericArrayUndo.canManage || experimentBusy}
							onClick={() => setGenericArrayUndo(!genericArrayUndo.enabled)}
						>
							{experimentBusy
								? __('Saving…', 'configops')
								: genericArrayUndo.enabled
									? __('Disable verified key undo', 'configops')
									: __('Enable verified key undo', 'configops')}
						</button>
					</header>
					<p>
						{__('For wp_options arrays with no tested adapter owner, ConfigOps reverses only snapshot-verified keys and preserves unrelated later changes. List-index edits, roots, secrets, truncated diffs, and changed target fields are refused.', 'configops')}
						{!genericArrayUndo.canManage && <> {__('A site administrator must change this setting.', 'configops')}</>}
					</p>
				</section>
			)}
			<div className="configops-support-head" aria-hidden="true">
				<span>{__('Plugin', 'configops')}</span><span>{__('Status', 'configops')}</span><span>{__('Support', 'configops')}</span><span></span>
			</div>
			{adapters.map((adapter) => <AdapterRow adapter={adapter} key={adapter.id} />)}
		</div>
	);
}
