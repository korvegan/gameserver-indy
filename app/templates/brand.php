<header class="brand">
<svg class="brand-mark" aria-hidden="true" viewBox="0 0 48 64" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round">
<circle cx="24" cy="31" r="20"/><circle cx="24" cy="31" r="16" opacity=".55"/>
<path d="M24 2v8m-3-6h6m-6 6h6v34l-3 16-3-16V10Zm3 1v42M16 15l8-3 8 3M8 20l4 2m-7 9h5m-2 11 4-2m24-18 4-2m-2 11h5m-7 9 4 2"/>
</svg><span>STURMKLINGEN</span>
<?php if (isset($navigation)): ?><a class="brand-nav" href="<?= e($navigation['href']) ?>">
<?php if ($navigation['href'] === '/'): ?><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="m15 5-7 7 7 7"/></svg><?php else: ?><svg aria-hidden="true" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2Z"/></svg><?php endif; ?>
<?= e($navigation['label']) ?></a><?php endif; ?>
</header>
