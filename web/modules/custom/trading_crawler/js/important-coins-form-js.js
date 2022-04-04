(function ($) {
  
  $.fn.addParamsToPagerLinks = function() {
    $("form.important-coins-form li.pager__item a").each(function() {
      var $this = $(this);       
      var _href = $this.attr("href"); 
      $this.attr("href", _href + '&test=test-value');
    });
  }

})(jQuery);
