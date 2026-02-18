(function(window, angular) {
    "use strict";
    angular.module('FileManagerApp').service('dropUploader', ['$http', '$config', function ($http, $config) {
        var self = this;
        self.requesting = false; 
        self.dropupload = function(fileList, path, success, error) {
            var form = new window.FormData();
        
            form.append('destination', '/'+ path.join('/'));
    
            for (var file in fileList) {
                var fileObj = fileList[file];
                typeof fileObj === 'object' && form.append('file-' + (1 + parseInt(file, null)), fileObj);
            }
            
            self.requesting = true;
        
            $http.post($config.uploadUrl, form, {
                transformRequest: angular.identity,
                headers: {'Content-Type': undefined}
            }).success(function(data) {
                self.requesting = false;
                if (typeof data.error !== "undefined") {
                    typeof error === 'function' && error(data.error.message);
                } else {
                    typeof success === 'function' && success(data);
                }
            }).error(function(data) {
                self.requesting = false;
            });
        }
    }]);
})(window, angular);