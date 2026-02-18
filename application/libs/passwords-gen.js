/** 
 * jQuery plugin to check the strength of a password
 * 
 * @author      ShevAbam (http://www.shevarezo.fr)
 * @date        11 june 2014
 * @version     1.0
 * 
 * [ How to use it ]
 * 
    <script>
        $(function(){
            $('input#pwd').passwordstrength({
                'minlength': 6,
                'number'   : true,
                'capital'  : true,
                'special'  : true,
                'labels'   : {
                    'general'   : 'Le mot de passe doit avoir :',
                    'minlength' : 'Au moins {{minlength}} caractères',
                    'number'    : 'Au moins un chiffre',
                    'capital'   : 'Au moins une lettre majuscule',
                    'special'   : 'Au moins un caractère spécial'
                }
            });
        });
    </script>
 * 
 */

(function($){
    
    $.fn.passwordstrength = function(options) {
        
        // Options
        var settings = $.extend({
            'minlength': 8,
            'number'   : true,
            'numbers'  : true,
            'capital'  : true,
            'lowercase': true,
            'special'  : true,
            'valueInPass'  : true,
            'valueInPassFieldId'  : "",
            'labels'   : {
                'general'   : $(".vpTitle").html(),
                'minlength' : $(".vpMinLength").html(),
                'number'    : $(".vpNumber").html(),
                'numbers'   : $(".vpNumbers").html(),
                'capital'   : $(".vpCapital").html(),
                'lowercase' : $(".vpLowercase").html(),
                'special'   : $(".vpSpecial").html(),
                'valueInPass'   : $(".vpValueInPass").html()
                
            }
        }, options);

        
        return this.each(function(){
            var $this = $(this);

            // HTML
            $('<div id="passwordstrength-wrap" />').insertAfter($this);
            $('#passwordstrength-wrap').append('<strong>'+settings.labels.general+'</strong><ul></ul>');

            if (settings.minlength > 0)
                $('#passwordstrength-wrap ul').append('<li id="length">'+settings.labels.minlength.replace('{{minlength}}', settings.minlength)+'</li>');
            if (settings.number)
                $('#passwordstrength-wrap ul').append('<li id="pnum">'+settings.labels.number+'</li>');
            if (settings.lowercase)
                $('#passwordstrength-wrap ul').append('<li id="plowercase">'+settings.labels.lowercase+'</li>');
            if (settings.capital)
                $('#passwordstrength-wrap ul').append('<li id="capital">'+settings.labels.capital+'</li>');
            if (settings.special)
                $('#passwordstrength-wrap ul').append('<li id="spchar">'+settings.labels.special+'</li>');
            if (settings.numbers)
                $('#passwordstrength-wrap ul').append('<li id="pnums">'+settings.labels.numbers+'</li>');
            if (settings.valueInPass)
                $('#passwordstrength-wrap ul').append('<li id="pvalueInPass">'+settings.labels.valueInPass+'</li>');


            $this.on('focus keyup', function() {
                var value = $this.val();
                $(".help-block.error.password").hide()
                $('#passwordstrength-wrap').fadeIn(400);

                // password length
                if (value.length > 0)
                {
                    if (value.length >= settings.minlength)
                        $('#passwordstrength-wrap #length').addClass('valid');
                    else
                        $('#passwordstrength-wrap #length').removeClass('valid');
                }
         
                // at least 1 digit
                if (settings.number)
                {
                    if (value.match(/\d/))
                        $('#passwordstrength-wrap #pnum').addClass('valid');
                    else
                        $('#passwordstrength-wrap #pnum').removeClass('valid');
                }
                  // at least 1 lowercase
                if (settings.lowercase)
                {
                   if (value.match(/[a-z]/))
                        $('#passwordstrength-wrap #plowercase').addClass('valid');
                    else
                        $('#passwordstrength-wrap #plowercase').removeClass('valid');
                }
                
                // at least 123 digit
                if (settings.numbers)
                {
                    if (! value.match(/(012|123|234|345|456|567|678|789)/)){
                        $('#passwordstrength-wrap #pnums').addClass('valid');
                    } else {
                        $('#passwordstrength-wrap #pnums').removeClass('valid');
                    }
                }
                
                // 
                if (settings.valueInPass)
                {

                    if (! value.match($("#"+settings.valueInPassFieldId).val())){
                        $('#passwordstrength-wrap #pvalueInPass').addClass('valid');
                    } else {
                        $('#passwordstrength-wrap #pvalueInPass').removeClass('valid');
                    }
                }
         
                // at least 1 capital
                if (settings.capital)
                {
                    if (value.match(/[A-Z]/))
                        $('#passwordstrength-wrap #capital').addClass('valid');
                    else
                        $('#passwordstrength-wrap #capital').removeClass('valid');
                }
         
                // at least 1 special character
                if (settings.special)
                {
                    if (value.match(/[@\*/]/))
                        $('#passwordstrength-wrap #spchar').addClass('valid');
                    else
                        $('#passwordstrength-wrap #spchar').removeClass('valid');
                }
            });


            $this.blur(function () {
                $('#passwordstrength-wrap').fadeOut(400);
            });
        });
    }

})(jQuery);

/*! jquery-password-generator-plugin - v0.0.0 - 2015-10-23
* Copyright (c) 2015 Sergey Sokurenko; Licensed MIT */
(function ($) {
  $.passGen = function (options) {
    // Override default options with passed-in options
    options = $.extend({}, $.passGen.options, options);

    // Local varialbles declaration
    var charsets, charset = '', password = '', index;

    // Available character lists
    charsets = {
      'numeric'   : '0123456789',
      'lowercase' : 'abcdefghijklmnopqrstuvwxyz',
      'uppercase' : 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
      'special'   : '@*/'
    };

    // Defining merged character set
    $.each(charsets, function(key, value) {
      if (options[key]) {
        charset += value;
      }
    });

    //Generating position special character
    indexLocationSpecial = Math.floor(Math.random() * (options.length-3));
    indexCharacterSpecial = Math.floor(Math.random() * (charsets.special.length));
    characterSpecial = charsets.special[indexCharacterSpecial];
    
    // Generating the password
    for (var i=0; i< options.length-3; i++) {
      
      // defining random character index
      index = Math.floor(Math.random() * (charset.length-1));
      // adding the character to the password
      if (indexLocationSpecial == i) {
          password += characterSpecial;
      } else {
          password += charset[index];
      }
      //console.log("characterSpecial: "+characterSpecial+" indexLocationSpecial:"+indexLocationSpecial+" characterSpecial:"+characterSpecial+" i:"+i+ " password:"+password);
    }
    index = Math.floor(Math.random() * (charsets.numeric.length));
    password += charsets.numeric[index];
    
    index = Math.floor(Math.random() * (charsets.lowercase.length));
    password += charsets.lowercase[index];
    
    index = Math.floor(Math.random() * (charsets.uppercase.length));
    password += charsets.uppercase[index];

    // Returning generated password value
    return password;
  };

  // Default options
  $.passGen.options = {
    'length' : 10,
    'numeric' : true,
    'lowercase' : true,
    'uppercase' : true,
    'special'   : false
  };
}(jQuery));