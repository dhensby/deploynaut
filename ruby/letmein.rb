namespace :letmein do
	desc <<-DESC
		Requests CMS access on the target instance.
		Required arguments to the cap command: username, password
	DESC
	task :request do
		run "echo \"{\\\"user\\\":\\\"#{username}\\\",\\\"pass\\\":\\\"#{password}\\\"}\" | /usr/local/bin/letyouin"
	end
end
