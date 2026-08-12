import Hint from '../components/Hint.jsx';
import { useConfigOpsState } from '../data/store.js';

const levelLabel = (level, __) => {
	switch (level) {
		case 'full': return __('Supported', 'configops');
		case 'partial': return __('With limits', 'configops');
		default: return __('Not available yet', 'configops');
	}
};

const PluginState = ({ adapter }) => {
	const { __ } = window.wp.i18n;
	if (!adapter.installed) {
		return <span className="configops-plugin-state is-absent">{__('Not installed here', 'configops')}</span>;
	}
	if (!adapter.compatible) {
		return <span className="configops-plugin-state is-warning">{__('Version not tested', 'configops')}</span>;
	}
	if (!adapter.active) {
		return <span className="configops-plugin-state is-inactive">{__('Installed, inactive', 'configops')}</span>;
	}

	return <span className="configops-plugin-state is-ready">{__('Ready on this website', 'configops')}</span>;
};

const Capability = ({ capability, versionUntested }) => {
	const { __ } = window.wp.i18n;
	const level = versionUntested ? 'partial' : capability.level;
	const label = versionUntested ? __('Not tested on this version', 'configops') : levelLabel(level, __);

	return (
		<div className={`configops-support-capability is-${level}`}>
			<span>{capability.label}</span>
			<Hint label={`${capability.label}: ${label}`} trigger={label}>
				{versionUntested ? __('The installed version is outside this adapter’s tested range. ConfigOps records evidence but disables automatic undo.', 'configops') : capability.note}
			</Hint>
		</div>
	);
};

const AdapterRow = ({ adapter }) => {
	const { __ } = window.wp.i18n;
	const versionUntested = adapter.installed && !adapter.compatible;

	return (
		<details className="configops-support-row">
			<summary>
				<div className="configops-support-plugin">
					<span className="configops-support-mark" aria-hidden="true">{adapter.name.slice(0, 1)}</span>
					<div>
						<h3>{adapter.name}</h3>
						<p>{adapter.version ? `v${adapter.version}` : __('Adapter available', 'configops')} <span aria-hidden="true">·</span> {__('tested', 'configops')} {adapter.testedVersion}</p>
					</div>
				</div>
				<PluginState adapter={adapter} />
				<div className="configops-support-quick" aria-label={__('Support summary', 'configops')}>
					{adapter.capabilities.slice(0, 4).map((capability) => (
						<span key={capability.id} className={`is-${versionUntested ? 'partial' : capability.level}`} title={`${capability.label}: ${versionUntested ? __('Not tested on this version', 'configops') : levelLabel(capability.level, __)}`}>
							<span className="screen-reader-text">{capability.label}: </span>{versionUntested || capability.level === 'partial' ? '◐' : capability.level === 'full' ? '●' : '○'}
						</span>
					))}
				</div>
				<span className="configops-chevron" aria-hidden="true"></span>
			</summary>
			<div className="configops-support-detail">
				<section>
					<h4>{__('What works today', 'configops')}</h4>
					<div className="configops-support-capabilities">
						{adapter.capabilities.map((capability) => <Capability capability={capability} versionUntested={versionUntested} key={capability.id} />)}
					</div>
				</section>
				<div className="configops-support-notes">
					<section>
						<h4>{__('Understood', 'configops')}</h4>
						<ul>{adapter.coverage.map((item) => <li key={item}>{item}</li>)}</ul>
					</section>
					<section>
						<h4>{__('Known limits', 'configops')}</h4>
						<ul>{adapter.limitations.map((item) => <li key={item}>{item}</li>)}</ul>
					</section>
				</div>
				<footer>
					<span>{__('Adapter schema', 'configops')} <code>v{adapter.schemaVersion}</code></span>
					{adapter.sourceUrl && <a href={adapter.sourceUrl} target="_blank" rel="noreferrer" aria-label={__('Review source contract (opens in a new tab)', 'configops')}>{__('Review source contract', 'configops')} <span aria-hidden="true">↗</span></a>}
				</footer>
			</div>
		</details>
	);
};

export default function SupportMatrix() {
	const { __ } = window.wp.i18n;
	const state = useConfigOpsState();
	const adapters = state.adapters || [];

	return (
		<>
			<header className="configops-support-intro">
				<span className="configops-eyebrow">{__('Compatibility contract', 'configops')}</span>
				<h2>{__('Know what ConfigOps understands.', 'configops')}</h2>
				<p>{__('Each adapter states what it can explain, hide, and undo. Open a plugin for the exact boundaries—no blanket “compatible” badge.', 'configops')}</p>
			</header>

			<section className="configops-support-legend" aria-label={__('Support level legend', 'configops')}>
				<span><i className="is-full" aria-hidden="true">●</i> {__('Supported', 'configops')}</span>
				<span><i className="is-partial" aria-hidden="true">◐</i> {__('With limits', 'configops')}</span>
				<span><i className="is-planned" aria-hidden="true">○</i> {__('Not available yet', 'configops')}</span>
				<Hint label={__('How should I read this?', 'configops')} align="end">
					{__('Support is pinned to tested plugin versions. ConfigOps still records unfamiliar values, but labels them for review instead of guessing.', 'configops')}
				</Hint>
			</section>

			<div className="configops-support-ledger">
				<div className="configops-support-head" aria-hidden="true">
					<span>{__('Plugin', 'configops')}</span><span>{__('This website', 'configops')}</span><span>{__('Coverage', 'configops')}</span><span></span>
				</div>
				{adapters.map((adapter) => <AdapterRow adapter={adapter} key={adapter.id} />)}
			</div>
		</>
	);
}
