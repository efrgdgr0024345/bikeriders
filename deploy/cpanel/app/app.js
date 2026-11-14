const search = document.querySelector('#search');
if (search) {
  search.addEventListener('input', () => {
    const query = search.value.trim().toLowerCase();
    let visible = 0;
    document.querySelectorAll('[data-search]').forEach(card => {
      card.hidden = !card.dataset.search.includes(query);
      if (!card.hidden) visible++;
    });
    const empty = document.querySelector('#no-results');
    if (empty) empty.hidden = visible !== 0;
  });
}
