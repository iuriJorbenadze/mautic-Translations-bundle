<?php
namespace MauticPlugin\AiTranslateBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomButtonEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;

class ButtonSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RouterInterface $router,
        private LoggerInterface $logger
    ) {}

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
        if ($loc !== 'page_actions') {
            return;
        }

        $this->logger->info('[AiTranslate] injecting dropdown item', ['location' => $loc]);

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

  var targetLang = prompt('Target language code (e.g. DE, FR, ES):','DE');
  if(!targetLang || targetLang.trim()===''){ return false; }

  try{ if(window.Mautic && Mautic.showLoadingBar){ Mautic.showLoadingBar(); } }catch(_){}

  // Prepare form data
  var form = new URLSearchParams();
  form.append('targetLang', targetLang.trim().toUpperCase());

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
      console.error('AiTranslate error:', err);
      alert('Unexpected error, check console.');
  })
  .finally(function(){
      try{ if(window.Mautic && Mautic.stopLoadingBar){ Mautic.stopLoadingBar(); } }catch(_){}
  });

  return false;
})(event);
JS
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

        $this->logger->info('[AiTranslate] dropdown item added', ['location' => $loc]);
    }
}
