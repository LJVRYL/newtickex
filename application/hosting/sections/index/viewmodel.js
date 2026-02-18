define(['knockout'], function(ko) {

    var IndexVM = function() {
        this.user = ko.observable('');
        this.password = ko.observable('');
        this.status = ko.observable({
            "statuscode": '',
            "message": ''
        });
        this.domainReg = ko.observable('');
        this.typeaheadLastItem = {};
    };

    IndexVM.prototype.init = function() {
        this.initTypeahead();

        $('.dropdown-menu.dont-close').click(function(e) {
            e.stopPropagation();
        });

        $(window.document).on('click', '.dropdown-toggle.dont-close', function() {
            $('#ferozo-suggest').val('').focus();
        });
    };

    IndexVM.prototype.getAllMenuItems = function() {
        var allItems = [];
        for (var i in window.FerozoUtils.menuActions) {
            var item = window.FerozoUtils.menuActions[i];
            allItems.push(item);
        }
        for (var i in window.FerozoUtils.menu) {
            var item = window.FerozoUtils.menu[i];
            allItems.push(item);
            for (var ii in item.childs) {
                var subitem = item.childs[ii];
                allItems.push(subitem);
            }
        }
        return allItems;
    };

    IndexVM.prototype.initTypeahead = function() {
        var self = this;

        $('#ferozo-suggest').unbind().typeahead({
            "name": 'others',
            "local": self.getAllMenuItems(),
            "template" : "<div class='animated fadeIn superfast typeahead-item' data-redirection='{{link}}' onclick='$(\"#ferozo-suggest\").val(\"\");$(\".dropdown.open\").removeClass(\"open\"); window.location.href=\"{{link}}\"'><i class='icon {{icon}}'></i> {{value}}</div>",
            "engine": Hogan,
            "limit": 8
        }).keydown(function(e) {
            try {
                var active = $('.tt-suggestion.tt-is-under-cursor').first().find('div.typeahead-item');
                if (active && active.attr('data-redirection')) {
                    self.typeaheadLastItem.lnk = active.attr('data-redirection');
                    self.typeaheadLastItem.html = active.html();
                }
                var regexp = new RegExp($(this).val() + '$');
                if (e.keyCode === 13 && self.typeaheadLastItem.html.toString().match(regexp)) {
                    $('.dropdown.open').removeClass('open');
                    $('#ferozo-suggest').val('');
                    window.location.href = self.typeaheadLastItem.lnk;
                };
            } catch (error) {}
        });
    };

    IndexVM.prototype.goRegDomain = function() {
        var self = this;
        if(self.domainReg()) {
            var utmMedium;
            var utmContent;
            var tld;
            var urlReg = "https://donweb.com/es-ar/registro-de-dominios";
            var string = self.domainReg().trim();
            substring = ".";
            utmMedium = "banner";
            utmContent = "home_dashboard";
            if(string.indexOf(substring) !== -1) {
                tld = string.substr(string.indexOf(".") + 1);
                if (tld == "") {
                    tld = "com"    
                }
                string = string.substr(0, string.indexOf("."));
            } else {
                tld = "com"
            }
            window.open(urlReg+"?dominio="+string+"&tld="+tld+"&utm_source=panel_ferozo&utm_medium="+utmMedium+"&utm_campaign=cs-ferozo-dominio&utm_content="+utmContent, "_blank");
        }
    };

    IndexVM.prototype.checkEnterRegDomain = function(data , event){
        var self = this;
        if (event.which === 13) {
            $(event.target).change();
            self.goRegDomain();
        } else {
            return true;
        }
    };    
    
    return IndexVM;
});
