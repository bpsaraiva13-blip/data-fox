// Data Fox — microinterações: inclinação com brilho nos cartões, botões magnéticos e entrada em cascata.
(() => {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches
  if (reduce) return

  document.addEventListener('pointermove', (e) => {
    if (e.pointerType !== 'mouse') return
    const card = e.target.closest?.('.fi-ta-content-grid .fi-ta-record')
    document.querySelectorAll('.fi-ta-record[data-df-tilt]').forEach((c) => {
      if (c !== card) { c.removeAttribute('data-df-tilt'); c.style.setProperty('--rx', '0deg'); c.style.setProperty('--ry', '0deg') }
    })
    if (!card) return
    const r = card.getBoundingClientRect()
    const x = (e.clientX - r.left) / r.width
    const y = (e.clientY - r.top) / r.height
    card.setAttribute('data-df-tilt', '')
    card.style.setProperty('--mx', `${x * 100}%`)
    card.style.setProperty('--my', `${y * 100}%`)
    card.style.setProperty('--ry', `${(x - 0.5) * 6}deg`)
    card.style.setProperty('--rx', `${(0.5 - y) * 6}deg`)
  }, { passive: true })

  // Entrada em cascata dos cartões sempre que a grade é renderizada
  const stagger = (root) => {
    root.querySelectorAll('.fi-ta-content-grid .fi-ta-record:not([data-df-in])').forEach((el, i) => {
      el.setAttribute('data-df-in', '')
      el.classList.add('df-reveal')
      el.style.animationDelay = `${Math.min(i, 16) * 35}ms`
    })
  }
  document.addEventListener('DOMContentLoaded', () => stagger(document))
  document.addEventListener('livewire:navigated', () => stagger(document))
  new MutationObserver(() => stagger(document)).observe(document.documentElement, { childList: true, subtree: true })
})()
