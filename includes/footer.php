  </div><!-- end #page-wrapper -->
</div><!-- end #app -->

<script>
function closeSidebar() {
  const sidebar = document.getElementById('sidebar');
  if (sidebar) sidebar.classList.add('-translate-x-full');
}
function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');
  if (sidebar) sidebar.classList.toggle('-translate-x-full');
}
</script>
</body>
</html>
