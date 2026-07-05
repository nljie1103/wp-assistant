(function(){
  document.addEventListener('click', function(e){
    if(e.target && e.target.classList.contains('jrmu-check-all')){
      document.querySelectorAll('.jrmu-post-check').forEach(function(el){el.checked = e.target.checked;});
    }
    if(e.target && e.target.classList.contains('jrmu-copy-nginx')){
      var ta=document.querySelector('.jrmu-nginx');
      if(!ta){return;}
      ta.select();
      try{document.execCommand('copy');}catch(err){}
      var span=document.createElement('span');
      span.className='jrmu-copy-ok';
      span.textContent='已复制';
      e.target.parentNode.appendChild(span);
      setTimeout(function(){span.remove();},1800);
    }
  });
})();
