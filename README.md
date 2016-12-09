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

## Architectural Considerations for Scalability

### A better design for Storage (and one of the reasons to choose Containerization)

The current code does nothing special to store the uploaded files. It just uses the disk. As written, it does not even use the host's disk, it uses the filesystem inside the container, which is volatile and hence not recommended at all. I will explain why I made that choice in a little bit. But before that, let us think through the topic of portability at scale.

What will make this web application truly portable? The PHP and Apache parts are very portable, all platforms/ Linux distros/ public or private clouds will run them. It then boils down to the storage of the uploaded files. If we were to scale this application to millions of users, we will need a large and safe (read *backed-up, disaster-proof*) space to store all the uploaded files. That brings us to the cloud - one of the cheaper ways to store tons of stuff. But then as soon as you choose one of the public clouds for storage, portability is at risk because the application will now have to use the storage APIs/SDK specific to that platform.

It is clear, therefore, that we have to build an abstraction layer for persistent storage. Docker containers provide a great way to do that using [named volumes](https://docs.docker.com/engine/tutorials/dockervolumes/) or data-only containers. If your application is made up of 4 or 5 containers, imagine one of them being your *[storage micro-service](http://www.tricksofthetrades.net/2016/03/14/docker-data-volumes/)*. It probably uses a [volume plugin](https://docs.docker.com/engine/extend/plugins_volume/) to abstract the storage out to AWS EBS or Azure Files - or a Ceph or GlusterFS cluster - the [list](https://docs.docker.com/engine/extend/legacy_plugins/) of such plugins is already quite long, and growing longer every month. The other containers then can use the --volumes-from option to mount these persistent data volumes from the storage micro-service container.

In this current codebase, I have kept it quick and simple by storing the files inside the container's filesystem itself. The slightly more secure solution would be to map the host machine's disk into the container using the -v flag, and storing the files on some location of the docker host. However, seeing that my host was a VM on the cloud, I saw little point in that. Coupled with the fact that apache2 cannot write to a mounted volume owned by root, I would have needed to implement the solution described [here](http://stackoverflow.com/questions/23544282/what-is-the-best-way-to-manage-permissions-for-docker-shared-volumes). I have left that as an exercise for the near future.

### Security at Scale

Currently, I use inline PHP [openssl-encrypt](http://php.net/openssl-encrypt) to encrypt the file at rest while it is being uploaded, and [openssl-decrypt](http://php.net/openssl-decrypt) to decrypt it while it is being downloaded. While these are handy PHP functions, and will work fairly well unless the file sizes are not very large, this is **not** a very scalable solution. Encrypting and decrypting large files inline will limit the concurrent maximum number of users the system can support.

There are several high-level architectural solutions for this problem. One way is to let users choose client-side encryption if they so desire. Javascript frameworks like [crypto-js](https://code.google.com/archive/p/crypto-js/) or [crypto-browserify](https://github.com/crypto-browserify/crypto-browserify) can be used for this, like [this POC](https://github.com/hellais/up-crypt) does. The advantage of this solution is that encryption/ decryption does not bog the server down by using up all the compute resources - hence it can scale to a higher concurrency. Admittedly, you have to create a specially designed download page instead of sharing a simple http/https link that can be used on any REST client. But hey - you got to do the work *somewhere*.

Another way to solve this problem is to decouple the encryption process on the server-side and make it *asynchronous*. Here, the file will get uploaded, but downloading it won't be allowed instantaneously. An asynchronous process will encrypt it in-place, and mark it as *downloadable*. Storing such metadata around the file will, of course, need a database - but a metadata management system will anyway become mandatory as we get to these complex requirements.

This last solution, or at least variations of it, is actually implemented under the hood by EBS or S3 or Azure - it is just we do not see it, and we just enjoy the fruits of someone else's labor. That brings us to yet another kind of solution to this problem - buy instead of build :) Just use docker volume plugins that lets you store the files in the public cloud and check the *encrypted* box!

How about encryption on the wire if we use server-side encryption? The obvious answer to this is to offer **https** download links, which is easy to implement, so that would be a TODO for this small project of mine.

### High Availability, Fault Tolerance

A containerized solution will need a container orchestration platform with good scheduling prowess to ensure HA and Fault Tolerance. Luckily, we are not short on the choices here, and the current war going on to capture the market makes all of us winners. Kubernetes and DC/OS, in my opinion, are the fore-runners. [ECS](https://aws.amazon.com/ecs/) has proprietary scheduling, which encourages vendor lock-in more than [ACS](https://azure.microsoft.com/en-us/services/container-service/) does. But all public clouds let you run your own IaaS clusters running the scheduler of your choice - if you want more control.

A best practice for HA is to keep the application code as stateless as possible. The code as it is now is entirely stateless (not the file storage though, but we are just talking about the application code here). However, as metadata and session management requirements creep in, it may be a challenge to keep it that way - especially if we increase the maximum allowed file size and allow users to resume interrupted downloads.

Here, the context is great to discuss some alternatives to just using Apache httpd as the HTTP server. Apache Traffic Server is not as out-of-the-box as apache2, but it lets us create [plugins](https://docs.trafficserver.apache.org/en/4.2.x/sdk/how-to-create-trafficserver-plugins.en.html) that can hook into the PUT and GET requests. It is possible to write or modify some plugins to enable "streaming" of large files where the HTTP Server acts as a pass-through for the file's contents as it makes it way to its final storage destination. Such a design will let the solution scale to a much higher number of concurrent users as the memory on the server will be more efficiently used - it will need slightly powerful CPU-s to offset that. It will also be easier to implement "resume interrupted upload/ download" feature in that case as spooling of the file at the web server layer is not happening, so the transaction becomes more atomic, hence more fault-tolerant.

### Caching

Caching becomes relevant if some of the uploaded files gets very popular, and we see many downloads happening on these hot files. If this is just a personal file-sharing service, where each uploaded file is only downloaded a few times, caching may remain a lower priority. However, if it turns into the next youtube or dropbox, we will need something really scalable to cache files. There are several time-tested solutions, for example, [Nginx can be used to front Apache](https://blog.rackspace.com/nginx-support-enables-massive-web-application-scaling) for a PHP application like this.

Memcached is a widely used solution for caching, but it is not a great solution under two cases (a) for large files (b) if the traffic spikes too quickly, it may not dynamically scale well. Redis is a great option, as it also has disk persistence built in, so the size of the cache is usually not a limiting factor. The PHP code itself can use [APC](http://pecl.php.net/package/APC) for getting maximum mileage out of a single server without being a distributed cache.

When it comes to the topic of caching for a web application, most discussion assume small objects. However, truly scalable caching for large objects like large files need custom solutions - and one that I built for Yahoo's CDN is one such customized solution.

If the solution becomes wildly popular, and people from all over the world start using it, using a commercial CDN like Akamai for caching may be a good idea.

### Load Balancing

Load Balancing solutions (as the application scales horizontally) are an important consideration. Public Cloud platforms have load balancers like ELB or Azure Load Balancers. However, sometimes they lack features like customizable routing rules. For example, a feature like resuming interrupted download may need sticky sessions based on client IP - which is a fairly common need for web applications handling sessions. It is a common thing to trust a stand-alone load balancer like HAProxy more. The challenge becomes in scaling these standalone load-balancers if there is a sudden traffic spike. The cloud-native load balancers are particularly good at at that. Hence, there are pros and cons - but for this application, the cloud-native solutions should be good enough.
