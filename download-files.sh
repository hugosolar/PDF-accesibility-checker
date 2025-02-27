#!/bin/bash

# Check if the CSV file is provided as an argument
if [ "$#" -ne 1 ]; then
    echo "Usage: $0 <csv_file>"
    exit 1
fi

csv_file=$1
directoryPath=$2

# Check if the directory exists
if [ ! -d "$directoryPath" ]; then
    echo "Directory not found: $directoryPath"
    exit 1
fi


# Check if the CSV file exists
if [ ! -f "$csv_file" ]; then
    echo "File not found: $csv_file"
    exit 1
fi

# Directory to save downloaded files
output_dir=$directoryPath

# Create the directory if it doesn't exist
if [ ! -d "$output_dir" ]; then
    mkdir -p "$output_dir"
fi

# Read the CSV file and download the files from the URL field
while IFS=, read -r url date title post_url editor code; do
    # Skip the header row
    if [ "$url" != "url" ]; then

        file_name=$(basename "$url")
        file_path="$output_dir/$file_name"
        
        # Check if the file already exists
        if [ -f "$file_path" ]; then
            echo "File already exists: $file_path"
        else
            echo "Downloading $url"
            wget "$url" --directory-prefix="$output_dir" --tries=1 --timeout=20 --no-check-certificate  --user-agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/92.0.4515.159 Safari/537.36"
        fi
    fi
done < "$csv_file"

echo "Removing spaces in file names..."
for file in ${output_dir}/*; do
    new_file=$(echo "$file" | tr ' ' '_')
    if [[ "$new_file" != *.pdf ]]; then
        new_file="${new_file}.pdf"
    fi
    mv "$file" "$new_file"
done

echo "Pass PDF accesibility checker"
# Loop through all the PDF files in the directory
for file in "$directoryPath"/*.pdf; do
    # Check if there are no PDF files
    if [ ! -e "$file" ]; then
        echo "No PDF files found in the directory."
        exit 1
    fi

    # Full path of the PDF file
    filePath=$file;
    echo "Processing file: $filePath"

    # Run the process-pdfs.js script with the file as a parameter
    node pdf-accessibility-checker.js "$filePath"
    if [ $? -ne 0 ]; then
        echo "Error processing file $filePath"
    fi
done

echo "done";