import {
	exportPackRequest,
	fetchPackDraft,
	previewPackRequest,
} from '../data/api.js';
import { applyPack, useConfigOpsState } from '../data/store.js';
import { formatValue } from '../format.js';

const MAX_PACK_BYTES = 1024 * 1024;

const messageFromError = (error, fallback) => (
	error && typeof error.message === 'string' && error.message ? error.message : fallback
);

const downloadPack = ({ filename, json }) => {
	const blob = new Blob([json], { type: 'application/json' });
	const url = URL.createObjectURL(blob);
	const anchor = document.createElement('a');
	anchor.href = url;
	anchor.download = filename;
	document.body.append(anchor);
	anchor.click();
	anchor.remove();
	URL.revokeObjectURL(url);
};

const Value = ({ value, emptyLabel, missing = false }) => {
	if (missing || typeof value === 'undefined') {
		return <span className="configops-pack-value-empty">{emptyLabel}</span>;
	}

	return (
		<pre>{formatValue(value, {
			empty: window.wp.i18n.__('Empty', 'configops'),
			booleanTrue: window.wp.i18n.__('On (true)', 'configops'),
			booleanFalse: window.wp.i18n.__('Off (false)', 'configops'),
		})}</pre>
	);
};

function ExportWorkbench({ state, close }) {
	const { __, sprintf } = window.wp.i18n;
	const [draft, setDraft] = window.wp.element.useState(null);
	const [selected, setSelected] = window.wp.element.useState(new Set());
	const [name, setName] = window.wp.element.useState('');
	const [description, setDescription] = window.wp.element.useState('');
	const [packVersion, setPackVersion] = window.wp.element.useState('1.0.0');
	const [busy, setBusy] = window.wp.element.useState(true);
	const [error, setError] = window.wp.element.useState('');

	window.wp.element.useEffect(() => {
		let current = true;
		setBusy(true);
		fetchPackDraft(state.selected.id)
			.then((next) => {
				if (!current) return;
				setDraft(next);
				setName(next.defaults.name);
				setDescription(next.defaults.description);
				setPackVersion(next.defaults.packVersion);
				setSelected(new Set(next.items.filter((item) => item.selected).map((item) => item.key)));
			})
			.catch((nextError) => current && setError(messageFromError(nextError, __('The Pack draft could not be prepared.', 'configops'))))
			.finally(() => current && setBusy(false));

		return () => { current = false; };
	}, [state.selected.id]);

	const toggle = (key) => {
		setSelected((current) => {
			const next = new Set(current);
			if (next.has(key)) next.delete(key);
			else next.add(key);
			return next;
		});
	};

	const save = async () => {
		setBusy(true);
		setError('');
		try {
			const exported = await exportPackRequest(state.selected.id, {
				name,
				description,
				pack_version: packVersion,
				selected: [...selected],
			});
			downloadPack(exported);
			close();
		} catch (nextError) {
			setError(messageFromError(nextError, __('The Pack could not be exported.', 'configops')));
		} finally {
			setBusy(false);
		}
	};

	return (
		<div className="configops-pack-panel">
			<header className="configops-pack-panel-header">
				<div>
					<span>{__('EXPORT FROM HISTORY', 'configops')}</span>
					<h3>{__('Save this Change Session as a Pack', 'configops')}</h3>
					<p>{__('Only complete, adapter-backed settings can leave this website. Protected options are excluded before a file is built.', 'configops')}</p>
				</div>
				<button className="button" type="button" onClick={close}>{__('Close', 'configops')}</button>
			</header>
			{error && <p className="configops-pack-error" role="alert">{error}</p>}
			{busy && !draft ? <p className="configops-pack-loading">{__('Inspecting settings…', 'configops')}</p> : draft && (
				<>
					<div className="configops-pack-fields">
						<label>
							<span>{__('Pack name', 'configops')}</span>
							<input type="text" maxLength="120" value={name} onChange={(event) => setName(event.target.value)} />
						</label>
						<label>
							<span>{__('Pack version', 'configops')}</span>
							<input type="text" maxLength="32" value={packVersion} onChange={(event) => setPackVersion(event.target.value)} />
						</label>
						<label className="configops-pack-description-field">
							<span>{__('Description', 'configops')}</span>
							<textarea maxLength="2000" rows="2" value={description} onChange={(event) => setDescription(event.target.value)} />
						</label>
					</div>
					<div className="configops-pack-selection-heading">
						<strong>{__('Settings in this Pack', 'configops')}</strong>
						<span>{sprintf(__('%1$d selected · %2$d excluded by policy', 'configops'), selected.size, draft.excludedCount)}</span>
					</div>
					<ul className="configops-pack-setting-list">
						{draft.items.map((item) => (
							<li className={!item.eligible ? 'is-excluded' : item.warnings.length ? 'has-warning' : ''} key={item.key}>
								<label>
									<input
										type="checkbox"
										checked={selected.has(item.key)}
										disabled={!item.eligible || busy}
										onChange={() => toggle(item.key)}
									/>
									<span className="configops-pack-setting-identity">
										<strong>{item.label}</strong>
										<code>{item.option}</code>
									</span>
								</label>
								<span className={`configops-pack-status ${item.eligible ? 'is-compatible' : 'is-blocked'}`}>
									{item.eligible ? __('Portable', 'configops') : __('Excluded', 'configops')}
								</span>
								<div className="configops-pack-notes">
									{item.warnings.map((warning) => <p key={warning.code}>⚠ {warning.message}</p>)}
									{item.reason && <p>{item.reason}</p>}
									{item.eligible && <Value value={item.value} missing={item.state === 'absent'} emptyLabel={__('Will be absent', 'configops')} />}
								</div>
							</li>
						))}
					</ul>
					<footer className="configops-pack-panel-actions">
						<p>{__('The JSON contains desired settings only—never old values, autoload flags, tables, SQL, or executable code.', 'configops')}</p>
						<button className="button button-primary" type="button" disabled={busy || selected.size === 0 || !name.trim()} onClick={save}>
							{busy ? __('Building Pack…', 'configops') : __('Download .configops.json', 'configops')}
						</button>
					</footer>
				</>
			)}
		</div>
	);
}

function ImportWorkbench({ state, close }) {
	const { __, sprintf } = window.wp.i18n;
	const [fileName, setFileName] = window.wp.element.useState('');
	const [pack, setPack] = window.wp.element.useState(null);
	const [selected, setSelected] = window.wp.element.useState(new Set());
	const [preview, setPreview] = window.wp.element.useState(null);
	const [previewSelection, setPreviewSelection] = window.wp.element.useState('');
	const [busy, setBusy] = window.wp.element.useState(false);
	const [error, setError] = window.wp.element.useState('');

	const selectionKey = [...selected].sort().join(',');
	const selectedPack = () => {
		const settings = pack.settings.filter((setting) => selected.has(setting.option));
		if (!preview) return { ...pack, settings };
		const requiredPluginFiles = new Set(
			preview.items
				.filter((item) => selected.has(item.option) && item.pluginFile)
				.map((item) => item.pluginFile),
		);
		const plugins = Object.fromEntries(
			Object.entries(pack.requirements?.plugins || {})
				.filter(([pluginFile]) => requiredPluginFiles.has(pluginFile)),
		);

		return {
			...pack,
			requirements: { ...pack.requirements, plugins },
			settings,
		};
	};

	const runPreview = async (nextPack = selectedPack()) => {
		if (!nextPack.settings.length) {
			setError(__('Select at least one setting before running the Apply Preview.', 'configops'));
			return;
		}
		setBusy(true);
		setError('');
		try {
			const next = await previewPackRequest(nextPack);
			setPreview(next);
			setPreviewSelection([...selected].sort().join(','));
		} catch (nextError) {
			setPreview(null);
			setError(messageFromError(nextError, __('The Pack could not be previewed.', 'configops')));
		} finally {
			setBusy(false);
		}
	};

	const loadFile = async (event) => {
		const file = event.target.files?.[0];
		if (!file) return;
		setError('');
		setPreview(null);
		if (file.size > MAX_PACK_BYTES) {
			setError(__('This file exceeds the 1 MiB Pack limit.', 'configops'));
			return;
		}
		try {
			const decoded = JSON.parse(await file.text());
			if (!decoded || typeof decoded !== 'object' || Array.isArray(decoded) || !Array.isArray(decoded.settings)) {
				throw new Error(__('This file does not contain a Pack object.', 'configops'));
			}
			const nextSelected = new Set(decoded.settings.map((setting) => setting.option));
			setFileName(file.name);
			setPack(decoded);
			setSelected(nextSelected);
			setBusy(true);
			const next = await previewPackRequest(decoded);
			setPreview(next);
			setPreviewSelection([...nextSelected].sort().join(','));
		} catch (nextError) {
			setPack(null);
			setSelected(new Set());
			setError(messageFromError(nextError, __('The Pack JSON could not be read.', 'configops')));
		} finally {
			setBusy(false);
			event.target.value = '';
		}
	};

	const toggle = (option) => {
		setSelected((current) => {
			const next = new Set(current);
			if (next.has(option)) next.delete(option);
			else next.add(option);
			return next;
		});
	};

	const performApply = async () => {
		setError('');
		const ok = await applyPack(selectedPack(), preview.planToken);
		if (ok) close();
	};

	const previewFresh = preview && previewSelection === selectionKey;
	const canApply = previewFresh && preview.canApply && state.capabilities.applyPacks && !busy && !state.ui.pending;
	const statusLabels = {
		already_matching: __('Already matching', 'configops'),
		will_change: __('Will change', 'configops'),
		incompatible: __('Incompatible', 'configops'),
		conflict: __('Conflict', 'configops'),
		excluded_safety: __('Excluded for safety', 'configops'),
	};

	return (
		<div className="configops-pack-panel">
			<header className="configops-pack-panel-header">
				<div>
					<span>{__('IMPORT TO THIS WEBSITE', 'configops')}</span>
					<h3>{__('Preview every Pack setting before Apply', 'configops')}</h3>
					<p>{__('ConfigOps checks requirements, adapter ownership, protected values, local references, and the current destination state.', 'configops')}</p>
				</div>
				<button className="button" type="button" onClick={close}>{__('Close', 'configops')}</button>
			</header>
			<div className="configops-pack-file-row">
				<label className="button">
					{pack ? __('Choose another Pack', 'configops') : __('Choose .configops.json', 'configops')}
					<input type="file" accept=".json,.configops.json,application/json" onChange={loadFile} />
				</label>
				<span>{fileName || __('No file selected', 'configops')}</span>
			</div>
			{error && <p className="configops-pack-error" role="alert">{error}</p>}
			{busy && !preview && <p className="configops-pack-loading">{__('Building Apply Preview…', 'configops')}</p>}
			{preview && (
				<>
					<div className="configops-pack-preview-title">
						<div><span>{__('PACK', 'configops')}</span><h4>{preview.pack.name}</h4><p>{preview.pack.description || __('No description supplied.', 'configops')}</p></div>
						<code>v{preview.pack.packVersion}</code>
					</div>
					<dl className="configops-pack-counts">
						<div><dt>{__('Compatible', 'configops')}</dt><dd>{preview.counts.compatible}</dd></div>
						<div><dt>{__('Already matching', 'configops')}</dt><dd>{preview.counts.alreadyMatching}</dd></div>
						<div><dt>{__('Will change', 'configops')}</dt><dd>{preview.counts.willChange}</dd></div>
						<div><dt>{__('Skipped', 'configops')}</dt><dd>{preview.counts.skipped}</dd></div>
						<div className={preview.counts.incompatible + preview.counts.conflicts > 0 ? 'has-problem' : ''}><dt>{__('Potential conflict', 'configops')}</dt><dd>{preview.counts.incompatible + preview.counts.conflicts}</dd></div>
					</dl>
					<ul className="configops-pack-requirements" aria-label={__('Pack requirements', 'configops')}>
						{preview.requirements.map((requirement) => (
							<li className={requirement.compatible ? 'is-compatible' : 'is-blocked'} key={`${requirement.type}-${requirement.name}`}>
								<strong>{requirement.name}</strong>
								<span>{requirement.version || __('Missing', 'configops')} · {requirement.constraint}</span>
							</li>
						))}
					</ul>
					<ul className="configops-pack-setting-list configops-pack-preview-list">
						{preview.items.map((item) => (
							<li className={['incompatible', 'conflict', 'excluded_safety'].includes(item.status) ? 'is-excluded' : item.warnings.length ? 'has-warning' : ''} key={item.key}>
								<label>
									<input type="checkbox" checked={selected.has(item.option)} disabled={busy || Boolean(state.ui.pending)} onChange={() => toggle(item.option)} />
									<span className="configops-pack-setting-identity"><strong>{item.label}</strong><code>{item.option}</code></span>
								</label>
								<span className={`configops-pack-status is-${item.status}`}>{statusLabels[item.status] || item.status}</span>
								<div className="configops-pack-notes">
									<p>{item.reason}</p>
									{item.warnings.map((warning) => <p key={warning.code}>⚠ {warning.message}</p>)}
									{!['incompatible', 'conflict', 'excluded_safety'].includes(item.status) && (
										<div className="configops-pack-diff">
											<div><span>{__('Current', 'configops')}</span><Value value={item.before} missing={item.beforeState === 'absent'} emptyLabel={__('Not set', 'configops')} /></div>
											<span aria-hidden="true">→</span>
											<div><span>{__('Desired', 'configops')}</span><Value value={item.after} missing={item.afterState === 'absent'} emptyLabel={__('Not set', 'configops')} /></div>
										</div>
									)}
								</div>
							</li>
						))}
					</ul>
					<footer className="configops-pack-panel-actions">
						<div>
							{!previewFresh && <p>{__('The selection changed. Refresh the preview before Apply.', 'configops')}</p>}
							{preview.activeCapture && <p>{__('Stop the active Change Session before applying a Pack.', 'configops')}</p>}
							{!state.capabilities.applyPacks && <p>{__('Your account can preview Packs but cannot apply them.', 'configops')}</p>}
						</div>
						<div>
							{!previewFresh && <button className="button" type="button" disabled={busy || selected.size === 0} onClick={() => runPreview()}>{__('Refresh preview', 'configops')}</button>}
							<button
								className="button button-primary"
								type="button"
								disabled={!canApply}
								onClick={() => {
									if (window.confirm(sprintf(__('Apply “%s”? ConfigOps will recheck every current value before the first write.', 'configops'), preview.pack.name))) {
										performApply();
									}
								}}
							>
								{state.ui.pending === 'apply-pack' ? __('Applying…', 'configops') : __('Apply Pack', 'configops')}
							</button>
						</div>
					</footer>
				</>
			)}
		</div>
	);
}

export default function PackWorkbench() {
	const { __ } = window.wp.i18n;
	const state = useConfigOpsState();
	const [mode, setMode] = window.wp.element.useState('');
	const selected = state.selected;
	const canExport = selected
		&& selected.status === 'completed'
		&& selected.mode === 'manual'
		&& selected.captureErrorCount === 0
		&& state.scope?.type !== 'network';

	window.wp.element.useEffect(() => {
		if (mode === 'export') setMode('');
	}, [selected?.id]);

	return (
		<>
			<div className="configops-pack-command">
				<div>
					<span>{__('PORTABLE CONFIGURATION', 'configops')}</span>
					<strong>{__('Configuration Packs', 'configops')}</strong>
					<p>{__('Capture a desired state here. Preview it completely before reproducing it elsewhere.', 'configops')}</p>
				</div>
				<div>
					<button
						className="button"
						type="button"
						title={!canExport ? __('Select a completed, integrity-clean named Change Session.', 'configops') : undefined}
						disabled={!canExport || Boolean(state.ui.pending)}
						onClick={() => setMode(mode === 'export' ? '' : 'export')}
					>
						{__('Save session as Pack', 'configops')}
					</button>
					<button className="button button-primary" type="button" disabled={Boolean(state.ui.pending)} onClick={() => setMode(mode === 'import' ? '' : 'import')}>
						{__('Import Pack', 'configops')}
					</button>
				</div>
			</div>
			{mode === 'export' && canExport && <ExportWorkbench state={state} close={() => setMode('')} />}
			{mode === 'import' && <ImportWorkbench state={state} close={() => setMode('')} />}
		</>
	);
}
