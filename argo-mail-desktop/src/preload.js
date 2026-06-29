// Most miedzy webowym Argo Mail a powloka. Bezpiecznie (contextIsolation) wystawia maly obiekt:
//   window.argoDesktop.notify({ from, subject, id })  -> natywny dymek Windows
//   window.argoDesktop.isDesktop  -> true tylko w programie (w zwyklej przegladarce: undefined)
//
// Dzieki temu front Argo Mail moze sam odpalic powiadomienie, gdy wykryje nowy mail —
// bez zadnego dodatkowego endpointu na serwerze.
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('argoDesktop', {
  isDesktop: true,
  version: () => ipcRenderer.invoke('app:version'),
  notify: (payload) => ipcRenderer.send('notify', payload || {})
});
