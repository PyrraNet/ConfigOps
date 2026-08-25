<script setup>
import { withBase } from 'vitepress';
import reviewScreenshot from '../../../../.wordpress-org/screenshot-1.png';
import smartUndoScreenshot from '../../../../.wordpress-org/screenshot-5.png';
import wordmarkLight from '../../../../assets/brand/configops-wordmark-light.svg';
import wordmarkDark from '../../../../assets/brand/configops-wordmark-dark.svg';
</script>

<template>
	<div class="co-home">
		<section class="co-hero" aria-labelledby="co-home-title">
			<div class="co-hero__identity">
				<picture>
					<source media="(prefers-color-scheme: dark)" :srcset="wordmarkDark">
					<img :src="wordmarkLight" width="232" height="52" alt="ConfigOps">
				</picture>
				<span aria-hidden="true"></span>
				<p>Documentation · 0.7.0</p>
			</div>
			<p class="co-kicker">Configuration management for WordPress</p>
			<h1 id="co-home-title">Capture the state.<br>Know every change.</h1>
			<p class="co-hero__dek">ConfigOps records the settings writes behind WordPress, turns safe Change Sessions into private portable Packs, previews every destination difference, and keeps the result conflict-checkable and undoable.</p>
			<div class="co-actions" aria-label="Start reading">
				<a class="co-action co-action--primary" :href="withBase('/guide/configuration-packs')">Build a Configuration Pack</a>
				<a class="co-action" :href="withBase('/security/failure-model')">Read the failure model</a>
			</div>
			<dl class="co-release-facts" aria-label="Current project facts">
				<div><dt>Release</dt><dd>0.7.0 · private Configuration Packs</dd></div>
				<div><dt>Runtime</dt><dd>WordPress 7.0–7.1 · PHP 8.2–8.5</dd></div>
				<div><dt>Storage</dt><dd>Local database · no account</dd></div>
				<div><dt>Release gates</dt><dd>Runtime matrix · real browser workflows</dd></div>
			</dl>
		</section>

		<section class="co-reading-path" aria-labelledby="co-pack-path-title">
			<header class="co-section-heading">
				<p class="co-kicker">New in 0.7 · private and declarative</p>
				<h2 id="co-pack-path-title">From one proven change to a reusable desired state.</h2>
				<p>A Pack is not a database snapshot. It carries only selected desired settings plus explicit WordPress, plugin, and adapter requirements.</p>
			</header>
			<ol>
				<li>
					<span>01</span>
					<div><h3>Capture</h3><p>Complete a named Change Session and remove anything protected, incomplete, or specific to that source.</p></div>
				</li>
				<li>
					<span>02</span>
					<div><h3>Preview</h3><p>Import one local JSON file and inspect requirements, matching settings, changes, exclusions, warnings, and conflicts.</p></div>
				</li>
				<li>
					<span>03</span>
					<div><h3>Apply and undo</h3><p>Recheck every baseline, record one Pack session in History, and use ordinary conflict-checked Undo when needed.</p><a :href="withBase('/guide/configuration-packs')">Read the Pack contract →</a></div>
				</li>
			</ol>
		</section>

		<section class="co-proof" aria-labelledby="co-proof-title">
			<header class="co-section-heading">
				<p class="co-kicker">Actual product evidence · no mockup</p>
				<h2 id="co-proof-title">Save normally. ConfigOps appears with the evidence.</h2>
				<p>A WordPress Reading Settings save produced six writes: one likely decision and five housekeeping writes. The card linked to the stored diff and offered Undo because every recorded value was restorable.</p>
			</header>
			<figure class="co-proof__figure">
				<p class="co-proof__mobile-note">Automatic evidence card · tap for the full settings screen</p>
				<a :href="reviewScreenshot" target="_blank" rel="noreferrer" aria-label="Open the full automatic ConfigOps evidence screenshot">
					<img :src="reviewScreenshot" width="1440" height="1100" alt="WordPress Reading Settings after a save with a ConfigOps evidence card showing six recorded writes, one likely decision, five housekeeping writes, Review writes, and Undo save">
				</a>
				<figcaption>
					<span>Automatic observation · WordPress Reading Settings</span>
					<strong>6 writes</strong>
					<strong>1 likely decision</strong>
					<strong>5 housekeeping</strong>
					<a :href="reviewScreenshot" target="_blank" rel="noreferrer">Open full evidence ↗</a>
				</figcaption>
			</figure>
		</section>

		<section class="co-proof co-proof--smart" aria-labelledby="co-smart-title">
			<header class="co-section-heading">
				<p class="co-kicker">New in 0.5 · opt-in per site</p>
				<h2 id="co-smart-title">Undo verified plugin keys without an adapter.</h2>
				<p>Enable verified key undo per site. ConfigOps offers it only when the complete associative-array patch agrees with both snapshots, every target still matches, and no adapter owns the option.</p>
			</header>
			<figure class="co-proof__figure co-proof__figure--smart">
				<p class="co-proof__mobile-note">Verified key undo policy · tap for the full Support contracts screen</p>
				<a :href="smartUndoScreenshot" target="_blank" rel="noreferrer" aria-label="Open the full ConfigOps verified key undo support screenshot">
					<picture>
						<source media="(max-width: 900px)" :srcset="withBase('/evidence/configops-smart-undo-mobile.png')">
						<img :src="smartUndoScreenshot" width="1440" height="1100" alt="ConfigOps Support contracts showing opt-in verified key undo, its refused structures, and the current adapter contracts">
					</picture>
				</a>
				<figcaption>
					<span>Unclaimed associative plugin arrays · site-local opt-in</span>
					<strong>Before · after · current</strong>
					<strong>Later keys preserved</strong>
					<a :href="withBase('/guide/undo-safely')">Read the safety contract →</a>
				</figcaption>
			</figure>
		</section>

		<section class="co-reading-path" aria-labelledby="co-path-title">
			<header class="co-section-heading">
				<p class="co-kicker">From Save to Undo</p>
				<h2 id="co-path-title">Three steps from Save to checked Undo.</h2>
			</header>
			<ol>
				<li>
					<span>01</span>
					<div><h3>Save one setting</h3><p>Use wp-admin normally. The first Options API write opens a request-local observation.</p><a :href="withBase('/guide/first-capture')">What gets recorded →</a></div>
				</li>
				<li>
					<span>02</span>
					<div><h3>Read every recorded write</h3><p>ConfigOps separates reviewable settings from runtime values, redacted secrets, and writes outside the Options API.</p><a :href="withBase('/guide/read-change')">How to read a change →</a></div>
				</li>
				<li>
					<span>03</span>
					<div><h3>Undo only matching state</h3><p>Before writing, ConfigOps checks the current values, snapshots, option owner, local references, and whether the observation completed.</p><a :href="withBase('/guide/undo-safely')">Checks before undo →</a></div>
				</li>
			</ol>
		</section>

		<section class="co-boundary" aria-labelledby="co-boundary-title">
			<p class="co-kicker">The line ConfigOps will not cross</p>
			<h2 id="co-boundary-title">Evidence is not a backup.</h2>
			<p>ConfigOps does not promise transactional rollback, copy media, deploy content, synchronize databases, or invent support for unknown custom tables. When evidence is incomplete or ownership is ambiguous, capability disappears instead of uncertainty being hidden.</p>
			<div class="co-boundary__links">
				<a :href="withBase('/security/secrets-privacy')">Secrets & privacy</a>
				<a :href="withBase('/reference/support')">Support contracts</a>
				<a :href="withBase('/reference/limits')">Known limits</a>
			</div>
		</section>
	</div>
</template>
