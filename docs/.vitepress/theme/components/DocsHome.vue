<script setup>
import { withBase } from 'vitepress';
import reviewScreenshot from '../../../../.wordpress-org/screenshot-1.png';
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
				<p>Documentation · 0.3.0</p>
			</div>
			<p class="co-kicker">Change intelligence for WordPress</p>
			<h1 id="co-home-title">The undo button<br>WordPress forgot.</h1>
			<p class="co-hero__dek">ConfigOps records supported settings saves automatically, reveals their hidden writes, removes probable credentials before storage, and reverses only state it can still prove safe.</p>
			<div class="co-actions" aria-label="Start reading">
				<a class="co-action co-action--primary" :href="withBase('/guide/getting-started')">Review a first change</a>
				<a class="co-action" :href="withBase('/security/failure-model')">Read the failure model</a>
			</div>
			<dl class="co-release-facts" aria-label="Release facts">
				<div><dt>Release</dt><dd>0.3.0 automatic recorder</dd></div>
				<div><dt>Runtime</dt><dd>WordPress 7.0+ · PHP 8.2–8.5</dd></div>
				<div><dt>Storage</dt><dd>Local database · no account</dd></div>
				<div><dt>Evidence</dt><dd>77.69% production · 79.20% trust boundaries</dd></div>
			</dl>
		</section>

		<section class="co-proof" aria-labelledby="co-proof-title">
			<header class="co-section-heading">
				<p class="co-kicker">Actual product evidence · no mockup</p>
				<h2 id="co-proof-title">One SMTP save, opened like a ledger.</h2>
				<p>The recorded request contains eight settings. The password is gone before persistence; seven supported values remain independently undoable.</p>
			</header>
			<figure class="co-proof__figure">
				<p class="co-proof__mobile-note">Swipe to inspect the real capture · tap the image for full size</p>
				<a :href="reviewScreenshot" target="_blank" rel="noreferrer" aria-label="Open the full ConfigOps review screenshot">
					<img :src="reviewScreenshot" width="1440" height="1689" alt="ConfigOps review of a real WP Mail SMTP capture showing eight visible settings, one redacted password, and seven safe undo targets">
				</a>
				<figcaption>
					<span>Capture #1 · WP Mail SMTP 4.9.0</span>
					<strong>8 settings</strong>
					<strong>1 redacted</strong>
					<strong>7 safe undo targets</strong>
					<a :href="reviewScreenshot" target="_blank" rel="noreferrer">Open full capture ↗</a>
				</figcaption>
			</figure>
		</section>

		<section class="co-reading-path" aria-labelledby="co-path-title">
			<header class="co-section-heading">
				<p class="co-kicker">The operator path</p>
				<h2 id="co-path-title">Read the evidence in order.</h2>
			</header>
			<ol>
				<li>
					<span>01</span>
					<div><h3>Save one setting</h3><p>Use wp-admin normally. ConfigOps opens an isolated observation when WordPress first writes configuration.</p><a :href="withBase('/guide/first-capture')">Recording boundaries →</a></div>
				</li>
				<li>
					<span>02</span>
					<div><h3>Read what actually happened</h3><p>Review intended settings separately from runtime noise, secrets, and unmanaged writes.</p><a :href="withBase('/guide/read-change')">Review anatomy →</a></div>
				</li>
				<li>
					<span>03</span>
					<div><h3>Undo only matching state</h3><p>ConfigOps checks current values, adapter versions, references, and capture integrity before writing.</p><a :href="withBase('/guide/undo-safely')">Undo contract →</a></div>
				</li>
			</ol>
		</section>

		<section class="co-boundary" aria-labelledby="co-boundary-title">
			<p class="co-kicker">The line ConfigOps will not cross</p>
			<h2 id="co-boundary-title">A recorder is not a backup.</h2>
			<p>ConfigOps does not promise transactional rollback, copy media, deploy content, synchronize databases, or invent support for unknown custom tables. When evidence is incomplete or ownership is ambiguous, capability disappears instead of uncertainty being hidden.</p>
			<div class="co-boundary__links">
				<a :href="withBase('/security/secrets-privacy')">Secrets & privacy</a>
				<a :href="withBase('/reference/support')">Support contracts</a>
				<a :href="withBase('/reference/limits')">Known limits</a>
			</div>
		</section>
	</div>
</template>
