define(['knockout', 
    //'dhm/sections/server/serverinfo/viewmodel', 'dhm/sections/server/services/viewmodel', 
    'whilecallback'], function(ko, WhileCallback) {

    var indexVM = function() {
        var self = this;
        this.user = ko.observable('');
        this.password = ko.observable('');
        this.status = ko.observable({
            "statuscode": '',
            "message": ''
        });
        this.typeaheadLastItem = {};
        
        this.newPanelAlerts = ko.observable('');
        
        this.countNewPanelAlerts = function() {
            $.postJSON("/dashboard/panelalert/count/open", function(data) {
                if (data.result) {
                    self.newPanelAlerts(data.result);
                }
            });
        };        
    };

//    indexVM.prototype.initDependencies = function() {
//        //Si es reseller no muestra info del server
////        new WhileCallback(function() {
////            return FerozoDhm.profileVM() && FerozoDhm.profileVM().isDhm();
////        }, function() {
////            if (FerozoDhm.profileVM().isDhm()) {
////                !FerozoDhm.serverinfoVM() && FerozoDhm.serverinfoVM(new serverinfoVM().init());
////            }
////        });
////        !FerozoDhm.servicesVM() && FerozoDhm.servicesVM(new servicesVM().init());
//    };

    indexVM.prototype.init = function() {

        this.countNewPanelAlerts();
        this.initTypeahead();

        //this.initDependencies();

        $('.dropdown-menu.dont-close').click(function(e) {
            e.stopPropagation();
        });

        $(window.document).on('click', '.dropdown-toggle.dont-close', function() {
            $('#ferozo-suggest').val('').focus();
        });
    };

    indexVM.prototype.getAllMenuItems = function() {
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

    indexVM.prototype.initTypeahead = function() {
        var self = this;

        $('#ferozo-suggest').unbind().typeahead({
            "name": 'others',
            "local": self.getAllMenuItems(),
            "template" : "<div class='animated fadeIn superfast typeahead-item' data-redirection='{{link}}' onclick='$(\"#ferozo-suggest\").val(\"\");$(\".dropdown.open\").removeClass(\"open\"); window.location.href=\"{{link}}\"'><i class='icon {{icon}}'></i> {{value}}</div>",
            "engine": window.Hogan,
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

    return indexVM;
});
