</main>

<footer class="mt-16 border-t border-sand-200 dark:border-forest-800 bg-sand-100/50 dark:bg-forest-800/50">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-8 flex flex-col sm:flex-row items-center justify-between gap-4 text-sm text-forest-700/70 dark:text-sand-100/60">
        <div class="flex items-center gap-2">
            <i data-lucide="mountain-snow" class="w-4 h-4"></i>
            <span class="font-[Manrope] font-semibold">GPX Manager</span>
            <span class="opacity-60">— outdoor edition</span>
        </div>
        <div class="flex items-center gap-4">
            <a href="https://github.com/puschpull/gpx_manager_claude" target="_blank" rel="noopener" class="hover:text-forest-600 dark:hover:text-terracotta-300 transition-colors">GitHub</a>
            <span class="opacity-60">© <?= date('Y') ?></span>
        </div>
    </div>
</footer>

<!-- Init Lucide ikon -->
<script>
    if (window.lucide) { lucide.createIcons(); }
    // Re-init po Alpine pohybu (kvůli x-show ikonám)
    document.addEventListener('alpine:initialized', () => {
        if (window.lucide) lucide.createIcons();
    });
</script>

<style>[x-cloak]{display:none!important}</style>

</body>
</html>
