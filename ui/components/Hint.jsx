export default function Hint({ label, children, align = 'start', trigger = null }) {
	const id = window.wp.element.useId();
	const hasTextTrigger = typeof trigger === 'string' && trigger.length > 0;

	return (
		<span className={`configops-hint configops-hint--${align}`}>
			<button
				className={`configops-hint-trigger ${hasTextTrigger ? 'is-text' : 'is-icon'}`}
				type="button"
				aria-label={label}
				aria-describedby={id}
			>
				{hasTextTrigger ? trigger : <span aria-hidden="true">i</span>}
			</button>
			<span id={id} className="configops-tooltip" role="tooltip">{children}</span>
		</span>
	);
}
