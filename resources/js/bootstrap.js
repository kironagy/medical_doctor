import axios from 'axios';
import { guardLocalOrigin } from './Utils/api';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Pin every relative axios request to the local embedded engine on native —
// see guardLocalOrigin() for why this is required (page origin can be
// production while online, which would otherwise silently redirect all
// relative-URL writes there).
guardLocalOrigin(axios);
