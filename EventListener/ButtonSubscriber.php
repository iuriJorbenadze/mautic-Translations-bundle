<?php

namespace MauticPlugin\LeuchtfeuerTranslationsBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomButtonEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;

class ButtonSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RouterInterface $router,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_BUTTONS => ['inject', 0],
        ];
    }

    public function inject(CustomButtonEvent $event): void
    {
        $loc = (string) $event->getLocation();

        // Only the Options dropdown on the email detail page
        if ('page_actions' !== $loc) {
            return;
        }

        $this->logger->info('[LeuchtfeuerTranslations] injecting dropdown item', ['location' => $loc]);

        $dropdownItem = [
            'attr'      => [
                'id'         => 'ai-translate-dropdown',
                // Dropdown entries are links; no "btn ..." classes
                'class'      => ' -tertiary -nospin',
                'href'       => '#',     // inert; prevents accidental GETs
                'role'       => 'button',
                'aria-label' => 'AI Translate',
                'onclick'    => <<<JS
(function(e){
  e.preventDefault(); e.stopPropagation();

  // Extract Email ID from /s/emails/view/{id}
  var m = (location.pathname || '').match(/\\/s\\/emails\\/view\\/(\\d+)/);
  var id = m ? m[1] : null;
  if(!id){ alert('Could not determine Email ID.'); return false; }

  // --- Simple language dropdown (no search), default 'DE' ---
    var DEEPL_LANGS = [
      { code: 'AR',     name: 'Arabic' },
      { code: 'BG',     name: 'Bulgarian' },
      { code: 'CS',     name: 'Czech' },
      { code: 'DA',     name: 'Danish' },
      { code: 'DE',     name: 'German' },
      { code: 'EL',     name: 'Greek' },
      { code: 'EN',     name: 'English' }, // Unspecified variant (back-compat)
      { code: 'EN-GB',  name: 'English (UK)' },
      { code: 'EN-US',  name: 'English (US)' },
      { code: 'ES',     name: 'Spanish' },
      { code: 'ES-419', name: 'Spanish (Latin American)' }, // next-gen text translation
      { code: 'ET',     name: 'Estonian' },
      { code: 'FI',     name: 'Finnish' },
      { code: 'FR',     name: 'French' },
      { code: 'HE',     name: 'Hebrew' }, // next-gen text translation
      { code: 'HU',     name: 'Hungarian' },
      { code: 'ID',     name: 'Indonesian' },
      { code: 'IT',     name: 'Italian' },
      { code: 'JA',     name: 'Japanese' },
      { code: 'KO',     name: 'Korean' },
      { code: 'LT',     name: 'Lithuanian' },
      { code: 'LV',     name: 'Latvian' },
      { code: 'NB',     name: 'Norwegian (Bokmål)' },
      { code: 'NL',     name: 'Dutch' },
      { code: 'PL',     name: 'Polish' },
      { code: 'PT',     name: 'Portuguese' }, // Unspecified variant (back-compat)
      { code: 'PT-BR',  name: 'Portuguese (Brazil)' },
      { code: 'PT-PT',  name: 'Portuguese (Portugal)' },
      { code: 'RO',     name: 'Romanian' },
      { code: 'RU',     name: 'Russian' },
      { code: 'SK',     name: 'Slovak' },
      { code: 'SL',     name: 'Slovenian' },
      { code: 'SV',     name: 'Swedish' },
      { code: 'TH',     name: 'Thai' }, // next-gen text translation
      { code: 'TR',     name: 'Turkish' },
      { code: 'UK',     name: 'Ukrainian' },
      { code: 'VI',     name: 'Vietnamese' }, // next-gen text translation
      { code: 'ZH',     name: 'Chinese' }, // Unspecified variant (back-compat)
      { code: 'ZH-HANS',name: 'Chinese (Simplified)' },
      { code: 'ZH-HANT',name: 'Chinese (Traditional)' }
    ];


  function openLanguagePicker(defaultCode){
    return new Promise(function(resolve){
      var overlay = document.createElement('div');
      overlay.style.position = 'fixed';
      overlay.style.inset = '0';
      overlay.style.background = 'rgba(0,0,0,0.4)';
      overlay.style.zIndex = '9999';
      overlay.addEventListener('click', function(ev){ if (ev.target === overlay) cleanup(null); });

      var modal = document.createElement('div');
      modal.style.position = 'absolute';
      modal.style.top = '50%';
      modal.style.left = '50%';
      modal.style.transform = 'translate(-50%, -50%)';
      modal.style.background = '#fff';
      modal.style.padding = '16px';
      modal.style.borderRadius = '8px';
      modal.style.boxShadow = '0 10px 30px rgba(0,0,0,0.2)';
      modal.style.width = '420px';
      modal.style.maxWidth = '90%';
      modal.setAttribute('role', 'dialog');
      modal.setAttribute('aria-modal', 'true');
      overlay.appendChild(modal);

      var title = document.createElement('h3');
      title.textContent = 'Choose target language';
      title.style.marginTop = '0';
      modal.appendChild(title);

      var select = document.createElement('select');
      select.style.width = '100%';
      select.style.margin = '8px 0';
      DEEPL_LANGS.forEach(function(l){
        var opt = document.createElement('option');
        opt.value = l.code;
        opt.textContent = l.name + ' (' + l.code + ')';
        if ((defaultCode || 'DE').toUpperCase() === l.code.toUpperCase()) opt.selected = true;
        select.appendChild(opt);
      });
      modal.appendChild(select);

      var actions = document.createElement('div');
      actions.style.display = 'flex';
      actions.style.justifyContent = 'flex-end';
      actions.style.gap = '8px';
      actions.style.marginTop = '12px';
      var cancelBtn = document.createElement('button');
      cancelBtn.type = 'button';
      cancelBtn.textContent = 'Cancel';
      var okBtn = document.createElement('button');
      okBtn.type = 'button';
      okBtn.textContent = 'Translate';
      okBtn.className = 'btn btn-primary';
      actions.appendChild(cancelBtn);
      actions.appendChild(okBtn);
      modal.appendChild(actions);

      function choose(){
        var code = (select.value || '').trim().toUpperCase();
        if (!code) { alert('Please choose a language.'); return; }
        cleanup(code);
      }

      function cleanup(result){
        document.removeEventListener('keydown', onKey);
        overlay.remove();
        resolve(result);
      }

      function onKey(ev){
        if (ev.key === 'Escape') cleanup(null);
        if (ev.key === 'Enter') choose();
      }

      cancelBtn.addEventListener('click', function(){ cleanup(null); });
      okBtn.addEventListener('click', choose);
      document.addEventListener('keydown', onKey);

      document.body.appendChild(overlay);
      select.focus();
    });
  }

  openLanguagePicker('DE').then(function(targetLang){
    if(!targetLang){ return false; }

    try{ if(window.Mautic && Mautic.showLoadingBar){ Mautic.showLoadingBar(); } }catch(_){}

    // Prepare form data
    var form = new URLSearchParams();
    form.append('targetLang', targetLang.toUpperCase());

    // CSRF token from global var rendered by Mautic
    var csrf = (typeof mauticAjaxCsrf !== 'undefined' && mauticAjaxCsrf) || '';
    if (!csrf) {
      alert('No CSRF token found. Please refresh the page and try again.');
      return false;
    }

    fetch('/s/plugin/ai-translate/email/' + id + '/translate', {
        method: 'POST',
        credentials: 'same-origin', // send session cookie
        headers: {
          'X-CSRF-Token': csrf,
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: form.toString()
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        var baseMsg = (d && d.message ? d.message : 'Done.');
        var warn = '\\n\\nNext step:\\nOpen the Email Builder, review the translated content, and click Save.';
        alert(baseMsg + warn);

        // Redirect to edit page of the cloned email
        if (d && d.clone && d.clone.urls && d.clone.urls.edit) {
            location.href = d.clone.urls.edit;
        }
    })
    .catch(function(err){
        console.error('LeuchtfeuerTranslations error:', err);
        alert('Unexpected error, check console.');
    })
    .finally(function(){
        try{ if(window.Mautic && Mautic.stopLoadingBar){ Mautic.stopLoadingBar(); } }catch(_){}
    });

  });

  return false;
})(event);
JS,
            ],
            'btnText'   => 'Clone & Translate',
            'iconClass' => 'ri-global-line',
            'primary'   => false, // force dropdown only
            'priority'  => 0.5,   // ordering within dropdown
        ];

        // Only on /s/emails/view/{id}
        $routeFilter = ['mautic_email_action', ['objectAction' => 'view']];

        // Pass the explicit location name
        $event->addButton($dropdownItem, 'page_actions', $routeFilter);

        $this->logger->info('[LeuchtfeuerTranslations] dropdown item added', ['location' => $loc]);
    }
}
