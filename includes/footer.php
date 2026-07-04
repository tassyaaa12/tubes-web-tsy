        </main>
    </div>

    <!-- Bootstrap 5.3 Bundle JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom Main JS -->
    <script src="assets/js/main.js"></script>
    
    <!-- Page Specific JS (jika ada) -->
    <?php if (isset($extra_js)): ?>
        <?= $extra_js ?>
    <?php endif; ?>
</body>
</html>
