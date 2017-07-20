source "https://rubygems.org"

gem 'sinatra',             :require => 'sinatra/base'
gem 'sinatra-namespace',   :require => 'sinatra/namespace'
gem 'sinatra-support',     :require => 'sinatra/support'

gem 'erubis'
gem 'rainbows', :require => nil
gem 'rake',                     :require => nil
gem 'hashie'
gem 'json'
gem 'dalli'
gem 'ratom', :require => 'atom'
gem 'jwt'

gem 'omniauth'
gem 'omniauth-indieauth'

gem 'mechanize'
#gem 'pingback'
#gem 'xml-simple', :require => 'xmlsimple'
gem 'rest-client'
#gem 'xmpp4r', :require => 'xmpp4r/client'
gem 'redis'
 
gem 'microformats2'
gem 'sanitize', '~>3.0.3'
gem 'indefinite_article'

gem 'mysql2',          '0.4.2'
gem 'dm-core'
gem 'dm-timestamps'
gem 'dm-migrations'
gem 'dm-aggregates'
gem 'dm-mysql-adapter'
gem 'dm-pager'

group :production do
  gem 'sucker_punch', '~> 1.0'
end

group :development do
  gem 'sucker_punch', '~> 1.0'
  gem 'shotgun',                :require => nil
  gem 'thin',                   :require => nil
end

group :test do
  gem 'minitest'
  gem 'webmock'
end
