define(['knockout'], function(ko) {
    var DomainRegVM = function() {
        'use strict';
        this.domain = ko.observable('');
        this.sourceId = ko.observable('');
    };

    DomainRegVM.prototype.init = function() {
    };

    DomainRegVM.prototype.openModal = function(data, event) {
        FerozoHosting.domainRegVM().sourceId(event.target.id);
        $('#modal-domainreg').modal('show');
    };

    DomainRegVM.prototype.goRegDomain = function() {
        var self = this;

        if(self.domain() == "" || self.domain() == undefined) {
            $('#modal-domainreg').modal('hide');
        }
        else {
            var utmMedium;
            var utmContent;
            var tld;
            var urlReg = "https://donweb.com/es-ar/registro-de-dominios";
            var string = self.domain().trim();
            substring = ".";
            
            if(self.sourceId() == "btn-reg-domain") {
                utmMedium = "boton";
                utmContent = "dominios_list";
            } else {
                utmMedium = "banner";
                utmContent = "dominios_dashboard";
            }
            
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
            $('#modal-domainreg').modal('hide');
        }
        
    };

    DomainRegVM.prototype.checkEnterRegDomain = function(data , event){
        var self = this;
        if (event.which === 13) {
            $(event.target).change();
            self.goRegDomain();
        } else {
            return true;
        }
    };  
    
    return DomainRegVM;
});