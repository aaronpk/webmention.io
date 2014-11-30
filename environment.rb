Encoding.default_internal = 'UTF-8'
require 'rubygems'
require 'bundler/setup'
require 'cgi'
require 'xmlrpc/marshal'

Bundler.require
Dir.glob(['lib', 'models', 'helpers'].map! {|d| File.join File.expand_path(File.dirname(__FILE__)), d, '*.rb'}).each {|f| require f}

unless File.exists? './config.yml'
  puts 'Please provide a config.yml file.'
  exit false
end

class ConfigHelper < Hashie::Mash
  def key; self['key'] end
end

SiteConfig = ConfigHelper.new YAML.load_file('config.yml')[ENV['RACK_ENV']] if File.exists?('config.yml')


class Controller < Sinatra::Base
  configure do

    helpers  Sinatra::UserAgentHelpers

    # Set controller names so we can map them in the config.ru file.
    set :controller_names, []
    Dir.glob('controllers/*.rb').each do |file|
      settings.controller_names << File.basename(file, '.rb')
#      require_relative "./#{file}"
    end

    use Rack::Session::Cookie, :key => 'rack.session',
                               :path => '/',
                               :expire_after => 2592000,
                               :secret => SiteConfig.session_secret

    set :root, File.dirname(__FILE__)
    set :show_exceptions, true
    set :raise_errors,    false
    set :protection, :except => [:frame_options, :json_csrf]

    use OmniAuth::Builder do
      provider :indieauth, :client_id => SiteConfig.base_url
    end

    DataMapper.finalize
    DataMapper.setup :default, SiteConfig.database_url

    set :views, 'views'
    set :erubis,          :escape_html => true
    set :public_folder, File.dirname(__FILE__) + '/public'
  end

  def p; params end
end

require_relative './controller.rb'
Dir.glob(['controllers'].map! {|d| File.join d, '*.rb'}).each do |f| 
  require_relative f
end
