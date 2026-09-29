(function(){
  'use strict';
  var input=document.getElementById('help-search');
  if(!input)return;
  var articles=[].slice.call(document.querySelectorAll('.help-main article'));
  var count=document.getElementById('search-count');
  var toggle=document.getElementById('help-index-toggle');
  var links=document.getElementById('help-links');
  function event(name,detail){
    if(typeof window.dataLayer!=='undefined'&&Array.isArray(window.dataLayer))window.dataLayer.push({event:name,article:detail});
  }
  function search(){
    var q=input.value.trim().toLocaleLowerCase('es');
    var visible=0;
    articles.forEach(function(article){
      var match=!q||article.textContent.toLocaleLowerCase('es').indexOf(q)!==-1;
      article.classList.toggle('hidden',!match);
      if(match)visible++;
    });
    count.textContent=q?(visible===1?'1 tema encontrado':visible+' temas encontrados'):'';
  }
  input.addEventListener('input',search);
  toggle.addEventListener('click',function(){
    var expanded=toggle.getAttribute('aria-expanded')==='true';
    toggle.setAttribute('aria-expanded',expanded?'false':'true');
    links.setAttribute('data-open',expanded?'false':'true');
  });
  links.addEventListener('click',function(e){
    if(e.target.closest('a')){
      toggle.setAttribute('aria-expanded','false');
      links.setAttribute('data-open','false');
    }
  });
  if('IntersectionObserver' in window){
    var observed={};
    var observer=new IntersectionObserver(function(entries){
      entries.forEach(function(entry){
        if(entry.isIntersecting&&!observed[entry.target.id]){
          observed[entry.target.id]=true;
          event('help_article_viewed',entry.target.id);
        }
      });
    },{threshold:0.35});
    articles.forEach(function(article){observer.observe(article)});
  }
})();
