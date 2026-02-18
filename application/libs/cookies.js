define([], function() {

    var Cookies = {

        set: function(name, value, secs) {
            var expires = '';
            if (secs) {
                var date = new Date();
                date.setTime(date.getTime() + (secs * 1000));
                expires = '; expires=' + date.toGMTString();
            }
            document.cookie = name + '=' + value + expires + '; path=/';
            return this;
        },

        get: function(name) {
            if (document.cookie.length > 0) {
                start = document.cookie.indexOf(name + '=');
                if (start !== -1) {
                    start = start + name.length + 1;
                    end = document.cookie.indexOf(';', start);
                    if (end === -1) {
                        end = document.cookie.length;
                    }
                    return unescape(document.cookie.substring(start, end));
                }
            }
            return '';
        },

        del: function(name) {
            return this.set(name, '', -1);
        },

        list: function() {
            var list = [];
            var cookies = document.cookie.split('; ');
            for (var i in cookies) {
                var cookie = cookies[i].split(/=(.+)/).splice(0,2);
                list.push(cookie);
            }
            return list;
        }

    };

    return Cookies;
});