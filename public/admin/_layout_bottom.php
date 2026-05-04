</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    document.addEventListener('submit', function(ev) {
        var form = ev.target;
        var inp = form && form.querySelector('input[name="due_at"]');
        if (inp && inp.value) {
            var d = new Date(inp.value);
            if (!isNaN(d.getTime())) inp.value = d.toISOString();
        }
    }, true);
})();
</script>
</body>
</html>

