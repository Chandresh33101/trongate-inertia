export const url = (path = '') =>
  (window.TG?.baseUrl ?? '/') + String(path).replace(/^\/+/, '');