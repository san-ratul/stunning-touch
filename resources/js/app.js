import Alpine from 'alpinejs';
import './analytics';
import { initTheme } from './theme';
import { mountTool } from './tools-loader';

window.Alpine = Alpine;

initTheme();
Alpine.start();

mountTool();
