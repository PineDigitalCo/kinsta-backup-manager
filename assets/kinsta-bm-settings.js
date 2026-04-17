(function () {
	'use strict';

	var cfg = window.kinstaBmSettings;
	if ( ! cfg || ! cfg.sites || ! cfg.sites.length ) {
		return;
	}

	var siteEl = document.getElementById( 'kinsta_bm_site_id' );
	var wrap = document.getElementById( 'kinsta_bm_env_field_wrap' );
	if ( ! siteEl || ! wrap ) {
		return;
	}

	function envLabel( e ) {
		return e.display_name && e.display_name !== '' ? e.display_name : e.name;
	}

	function findSite( siteId ) {
		var i;
		for ( i = 0; i < cfg.sites.length; i++ ) {
			if ( cfg.sites[ i ].id === siteId ) {
				return cfg.sites[ i ];
			}
		}
		return null;
	}

	function currentEnvValue() {
		var el = wrap.querySelector( '[name="kinsta_bm_env_id"]' );
		return el ? el.value : '';
	}

	function clearWrap() {
		while ( wrap.firstChild ) {
			wrap.removeChild( wrap.firstChild );
		}
	}

	function renderEnv() {
		var siteId = siteEl.value;
		var prev = currentEnvValue();
		var site;
		var envs;
		var hint;
		var inp;
		var sel;
		var opt0;
		var j;
		var e;
		var opt;

		clearWrap();

		if ( ! siteId ) {
			inp = document.createElement( 'input' );
			inp.type = 'text';
			inp.className = 'regular-text';
			inp.name = 'kinsta_bm_env_id';
			inp.id = 'kinsta_bm_env_id';
			inp.placeholder = cfg.i18n.envPlaceholder;
			inp.value = '';
			wrap.appendChild( inp );
			return;
		}

		site = findSite( siteId );
		envs = site && Array.isArray( site.environments ) ? site.environments : [];

		if ( envs.length === 0 ) {
			hint = document.createElement( 'p' );
			hint.className = 'description';
			hint.textContent = cfg.i18n.noEnvsHint;
			wrap.appendChild( hint );
			inp = document.createElement( 'input' );
			inp.type = 'text';
			inp.className = 'regular-text';
			inp.name = 'kinsta_bm_env_id';
			inp.id = 'kinsta_bm_env_id';
			inp.placeholder = cfg.i18n.envPlaceholder;
			inp.value = prev;
			wrap.appendChild( inp );
			return;
		}

		sel = document.createElement( 'select' );
		sel.name = 'kinsta_bm_env_id';
		sel.id = 'kinsta_bm_env_id';
		opt0 = document.createElement( 'option' );
		opt0.value = '';
		opt0.textContent = cfg.i18n.select;
		sel.appendChild( opt0 );
		for ( j = 0; j < envs.length; j++ ) {
			e = envs[ j ];
			opt = document.createElement( 'option' );
			opt.value = e.id;
			opt.textContent = envLabel( e ) + ' (' + e.id + ')';
			if ( prev === e.id ) {
				opt.selected = true;
			}
			sel.appendChild( opt );
		}
		wrap.appendChild( sel );
	}

	siteEl.addEventListener( 'change', renderEnv );
})();
