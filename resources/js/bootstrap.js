import axios from 'axios';
import { guardLocalOrigin } from './Utils/api';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Block any request whose URL is already absolute and non-local — see
// guardLocalOrigin() / localApiUrl() in Utils/api.js for why relative URLs
// are intentionally left alone (RequestRouter on the native side already
// routes them locally by path, and forcing them absolute breaks CORS).
guardLocalOrigin(axios);
