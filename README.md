# A Dockerized LAMP web-app - upload files and share links

This is a simple LAMP stack web application that enables one to upload files, store the uploaded files <b>encrypted-at-rest</b>, and securely share links for downloading. To keep it secure, these links expire in a day, and the uploader can protect the link with a password.

The primary point of this application is <b>not</b> to show how to write a file-sharing application with Apache and PHP - hence the code is simple, it does not have bells and whistles. This is just an example functionality - based on which, my goal is to have an architectural discussion on how such a cloud-native application could be run at scale using docker containers - securly, catering to millions of users. This particular application, for the sake of simplicity, does not use a database, but I hope to shed some light on all such aspects using this code as the starting point.

## How to run and test this app

First things first. These instructions will get you a copy of the project up and running on your local machine or any Linux VM in AWS or Azure or anywhere else - as long as it is running Docker.

### Set up your Docker host

To run this code, you need a Linux host running Docker. It does not matter how you get one or where you are running it. I will not go into the details of how to get one up and running, but there are instruction sets readily available for [AWS](http://docs.aws.amazon.com/AmazonECS/latest/developerguide/docker-basics.html) or [Azure](https://docs.microsoft.com/en-us/azure/virtual-machines/virtual-machines-linux-dockerextension).

### Get the code and run it

Once you are logged into a Linux host running Docker, clone this repository, and cd into *file-upload-link-share-simple* directory
```
XXXXX@MyDockerVM:~/file-upload-link-share-simple$ ls -l
total 12
drwxrwxr-x 2 Azure123 Azure123 4096 Dec  9 01:45 code
-rw-rw-r-- 1 Azure123 Azure123  249 Dec  9 01:45 Dockerfile
-rw-rw-r-- 1 Azure123 Azure123  133 Dec  9 01:45 README.md
```
Check if docker daemon is running, and if it is, build the docker image for this application (you can tag your image anything you want, I have tagged it "koushik/fileuploadsimple" below)
```
docker build -t koushik/fileuploadsimple .
```
Once the image is built, check the local image repository
```
docker images
```
Run the container, forwarding port 80 to some port of your host that you can access from the browser. In the example below, I have forwarded 80 to 80, as my VM on the cloud had inbound HTTP traffic allowed on port 80
```
docker run -d -p 80:80 koushik/fileuploadsimple
```
Access the web application from your browser
```
http://[[your server's DNS name or IP Address]]/form.html
```

### Functionality, assumptions, expected behavior

**How the password-protection feature works**: If the uploader specifies a password to protect the file, the downloader will have to specify the password directly in the URL/link as a query string parameter, "password". *The generated link adds this parameter at the end without the value*, so all the link-holder has to do is copy the link, paste it in the browser's address bar, and type the password at the end. If the password is correct, it will work. This also means that *if a password is provided, the generated link will not work as-is*, and there is ample warning and usage instruction provided on the page that displays the link.

**How the link-expiration feature works**: To keep it simple, I have compared the unix timestamp of when the download request is made, with that of when it was uploaded. If the difference is > 86,400 (however many seconds are in a day), I decline the request.

**The form** itself is self-explanatory. Things to keep in mind:
* I used an arbitrary upper file size limit of 1 MB. However, this can be easily increased - uploading a larger file will obviously take longer, but some UI animation magic may be done to keep the user interested. The encryption-at-rest feature is implemented using openssl - and the file is encrypted while writing, decrypted while reading. The larger the file, the longer will that take as well. I have some ideas to scale this design in the later sections (under *Architectural Considerations/ Security*).
* I set rules for the password so that I do not have to deal with special characters that the user can input. This enabled me to write something quickly, as the password is meant to be used directly on the download URL, and I did not want to deal with time-consuming URL santization code - which are all easy, but spending time with that was not the point of this app.

**Multiple uploads and management**: To keep things simple, if you upload the same file multiple times, the older ones are overwritten each time. There is no version control. There is no content management - once you upload a few files, you cannot come back and regenerate a link without uploading again. If you shared a link with someone, and they have let it expire, you have to upload it again to get a new link. These are all potential enhancements, and fairly easy to achieve, it is just more code. At some point, it is easy to get to a stage where you will need a database to manage the metadata - and then there will be more *state* to the system, and that state and persistence can let us do better things than what this barebone app cannot do now.

That's it! There's nothing more to it :)

## Architectural Considerations

### Why use Docker Containers and a better design for Storage

What will make this web application truly portable? The PHP code and Apache are very portable, all platforms/ Linux distros/ public or private clouds will run them. It then boils down to the storage of the uploaded files. If we were to scale this application to millions of users, we will need a large and safe (read *backed-up, disaster-proof*) space to store all the uploaded files. That brings us to the cloud - one of the cheaper ways to store tons of stuff. But then as soon as you choose one of the public clouds for storage, portability is at risk because the application will now haw to use 

### Storage of the uploaded files, Containers and Persistent Volumes

